import contextlib
import importlib.util
import io
import unittest
from unittest.mock import patch
from pathlib import Path

SPEC = importlib.util.spec_from_file_location('labctl', Path(__file__).parents[1] / 'scripts/labctl.py')
labctl = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(labctl)


class ControlTests(unittest.TestCase):
    def call(self, *args):
        with patch.object(labctl.os, 'geteuid', return_value=1000), contextlib.redirect_stdout(io.StringIO()):
            return labctl.main(list(args))

    def test_help_has_every_action(self):
        with contextlib.redirect_stdout(io.StringIO()) as out, self.assertRaises(SystemExit):
            labctl.main(['--help'])
        for action in labctl.ACTIONS:
            self.assertIn(action, out.getvalue())

    def test_root_refused_before_any_command(self):
        with patch.object(labctl.os, 'geteuid', return_value=0), patch.object(labctl, 'run') as run:
            with self.assertRaises(labctl.Error):
                labctl.main(['up'])
            run.assert_not_called()

    def test_stop_only_owned_unit(self):
        with patch.object(labctl, 'run', side_effect=['loaded', '']) as run:
            self.call('server-stop')
        self.assertEqual(run.call_args.args[0], ['systemctl', '--user', 'stop', labctl.UNIT])

    def test_network_stop_refuses_running_owned_server(self):
        with patch.object(labctl, 'run', return_value='active') as run:
            with self.assertRaises(labctl.Error):
                self.call('network-stop')
        self.assertFalse(any('sudo' in c.args[0] for c in run.call_args_list))

    def test_rule_scoped_to_interface_and_host(self):
        c = labctl.Controller('ztabc123', False)
        rule = c.rule('allow')
        self.assertEqual(rule, ['allow', 'in', 'on', 'ztabc123', 'proto', 'tcp', 'from', 'any', 'to',
                                labctl.HOST, 'port', '3080,5000:10000', 'comment', 'eval1-gns3-tcp-allow'])

    def test_duplicate_skip_is_failure(self):
        c = labctl.Controller('ztabc123', False)
        with patch.object(c, 'device', return_value='ztabc123'), patch.object(c, 'firewall_status', return_value='Status: active'), patch.object(labctl, 'run', return_value='Skipping inserting existing rule'):
            with self.assertRaises(labctl.Error):
                c.ports('allow')

    def test_inactive_firewall_never_mutated(self):
        c = labctl.Controller('ztabc123', False)
        with patch.object(labctl, 'run', return_value='Status: inactive') as run:
            with self.assertRaises(labctl.Error):
                c.ports('allow')
        self.assertEqual(run.call_count, 1)

    def test_close_uses_explicit_deny_and_readback(self):
        c = labctl.Controller('ztabc123', False)
        with patch.object(c, 'firewall_status', side_effect=['Status: active', 'Status: active\n# eval1-gns3-tcp-deny']), patch.object(labctl, 'run', return_value='Rule inserted') as run:
            c.port_rule('deny', 'tcp')
        self.assertEqual(run.call_args.args[0][:5], ['sudo', 'ufw', 'insert', '1', 'deny'])

    def test_dry_run_has_no_mutations(self):
        with patch.object(labctl, 'run', return_value='Status: active') as run:
            self.call('--dry-run', '--interface', 'ztabc123', 'ports-open')
        self.assertEqual(run.call_count, 2)

    def test_repeated_close_restores_existing_deny_after_failure(self):
        calls = []
        state = {'deny': True, 'inserts': 0}
        def fake(args, check=True):
            calls.append(args)
            if args[:3] == ['sudo', 'ufw', 'status']:
                return 'Status: active' + ('\n# eval1-gns3-tcp-deny' if state['deny'] else '')
            if args[:3] == ['sudo', 'ufw', 'delete']:
                state['deny'] = False
                return ''
            if args[:3] == ['sudo', 'ufw', 'insert']:
                state['inserts'] += 1
                if state['inserts'] == 1:
                    raise labctl.Error('temporary insertion failure')
                state['deny'] = True
                return 'Rule inserted'
            raise AssertionError(args)
        with patch.object(labctl, 'run', side_effect=fake):
            with self.assertRaises(labctl.Error):
                self.call('--interface', 'zttqhwfn5o', 'ports-close')
        self.assertTrue(state['deny'])
        self.assertEqual(state['inserts'], 2)

    def test_down_with_collected_unit_continues(self):
        calls = []
        def fake(args, check=True):
            calls.append(args)
            if 'show' in args:
                return 'not-found'
            if 'is-active' in args:
                return 'inactive'
            if args == ['sudo', 'systemctl', 'stop', 'zerotier-one']:
                return ''
            raise labctl.Error('unexpected command: ' + str(args))
        with patch.object(labctl, 'run', side_effect=fake), patch.object(labctl.Controller, 'ports') as ports:
            self.assertEqual(self.call('--interface', 'zttqhwfn5o', 'down'), 0)
        ports.assert_called_once_with('deny')
        self.assertIn(['sudo', 'systemctl', 'stop', 'zerotier-one'], calls)

    def test_dry_down_active_server_has_no_mutations(self):
        def fake(args, check=True):
            if args[:3] == ['sudo', 'ufw', 'status']:
                return 'Status: active'
            if 'is-active' in args:
                return 'active'
            if 'show' in args:
                return 'loaded'
            raise AssertionError(args)
        with patch.object(labctl, 'run', side_effect=fake):
            self.assertEqual(self.call('--dry-run', '--interface', 'zttqhwfn5o', 'down'), 0)

    def test_udp_range_excludes_games_and_dev_ports(self):
        c = labctl.Controller('zttqhwfn5o', False)
        self.assertIn('10000:16260,16263:20000', c.rule('allow', 'udp'))
        self.assertNotIn('16261', c.rule('allow'))
        self.assertNotIn('5173', c.rule('allow'))

    def test_invalid_interface_refused(self):
        with self.assertRaises(labctl.Error):
            labctl.Controller('eth0', False)

    def test_up_order_and_failed_start_closes_ports(self):
        c = labctl.Controller('ztabc123', False)
        events = []
        with patch.object(c, 'network_start', side_effect=lambda: events.append('network')), patch.object(c, 'ports', side_effect=lambda a: events.append(a)), patch.object(c, 'server_start', side_effect=labctl.Error('failed')):
            with self.assertRaises(labctl.Error):
                c.dispatch('up')
        self.assertEqual(events, ['network', 'allow', 'deny'])


if __name__ == '__main__':
    unittest.main()
