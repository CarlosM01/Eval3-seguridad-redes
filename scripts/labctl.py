#!/usr/bin/env python3
"""Local GNS3/ZeroTier control. Run as the desktop user, not root."""
import argparse
import configparser
import fcntl
import json
import os
from pathlib import Path
import re
import shlex
import shutil
import subprocess
import sys
import time

HOST = '10.163.179.125'
UNIT = 'gns3-eval1.service'
ACTIONS = ('up', 'down', 'server-start', 'server-stop', 'network-start',
           'network-stop', 'ports-open', 'ports-close', 'status', 'logs')


class Error(Exception):
    pass


def run(args, check=True):
    result = subprocess.run(args, text=True, capture_output=True, timeout=150,
                            env={**os.environ, 'LC_ALL': 'C'})
    if check and result.returncode:
        raise Error(f'{shlex.join(args)}: {result.stderr.strip() or result.stdout.strip()}')
    return result.stdout.strip()


class Controller:
    def __init__(self, interface, dry):
        if interface and not re.fullmatch(r'zt[a-zA-Z0-9]{1,13}', interface):
            raise Error('Interface must be a ZeroTier device name beginning with zt.')
        self.interface, self.dry = interface, dry

    def change(self, args):
        print(('[dry-run] ' if self.dry else '') + shlex.join(args))
        return '' if self.dry else run(args)

    def device(self):
        devices = json.loads(run(['ip', '-j', '-4', 'address', 'show']))
        matches = [d['ifname'] for d in devices if d['ifname'].startswith('zt')
                   and any(a.get('local') == HOST for a in d.get('addr_info', []))]
        if len(matches) != 1 or (self.interface and matches[0] != self.interface):
            raise Error(f'No unique ZeroTier interface owns {HOST}. Start ZeroTier first.')
        self.interface = matches[0]
        return self.interface

    def firewall_status(self):
        status = run(['sudo', 'ufw', 'status', 'numbered'])
        if not status.startswith('Status: active'):
            raise Error('UFW is inactive; no rules changed. Enable/configure it separately.')
        return status

    def rule(self, action, protocol='tcp'):
        ports = '3080,5000:10000' if protocol == 'tcp' else '10000:16260,16263:20000'
        return [action, 'in', 'on', self.interface, 'proto', protocol, 'from', 'any',
                'to', HOST, 'port', ports, 'comment', f'eval1-gns3-{protocol}-{action}']

    def ports(self, action):
        for protocol in ('tcp', 'udp'):
            self.port_rule(action, protocol)

    def port_rule(self, action, protocol):
        status = self.firewall_status()
        if not self.interface:
            # Rules retain the device name even while ZeroTier is stopped.
            saved = set(re.findall(r'\bon (zt\w+)\b[^\n]*# eval1-gns3-(?:tcp|udp)-(?:allow|deny)\s*$', status, re.M))
            if action == 'deny' and len(saved) == 1:
                self.interface = saved.pop()
            else:
                self.device()
        if action == 'allow' and not self.dry:
            self.device()
        opposite = 'deny' if action == 'allow' else 'allow'
        old = f'# eval1-gns3-{protocol}-{opposite}' in status
        current = f'# eval1-gns3-{protocol}-{action}' in status
        if current:
            self.change(['sudo', 'ufw', 'delete', *self.rule(action, protocol)])
        if self.dry:
            if old:
                self.change(['sudo', 'ufw', 'delete', *self.rule(opposite, protocol)])
            self.change(['sudo', 'ufw', 'insert', '1', *self.rule(action, protocol)])
            return
        # Exact nonempty comments prevent deleting somebody else's matching rule.
        # Remove our old action first: UFW cannot insert an identical tuple twice.
        if old:
            self.change(['sudo', 'ufw', 'delete', *self.rule(opposite, protocol)])
        try:
            output = self.change(['sudo', 'ufw', 'insert', '1', *self.rule(action, protocol)])
            if 'Skipping' in output:
                raise Error('Matching unmanaged UFW rule exists; refusing to take ownership.')
            if f'# eval1-gns3-{protocol}-{action}' not in self.firewall_status():
                raise Error('UFW readback did not confirm the managed rule.')
        except (Error, subprocess.SubprocessError):
            if old or current:
                previous = action if current else opposite
                self.change(['sudo', 'ufw', 'insert', '1', *self.rule(previous, protocol)])
            raise
        print('Rule verified. Existing connections are not interrupted by UFW rule changes.')

    def network_start(self):
        self.change(['sudo', 'systemctl', 'start', 'zerotier-one'])
        if self.dry:
            return
        for _ in range(30):
            try:
                self.device()
                return
            except Error:
                time.sleep(1)
        raise Error(f'ZeroTier started but {HOST} did not appear within 30 seconds.')

    def server_start(self):
        config = Path.home() / '.config/GNS3/2.2/gns3_server.conf'
        parser = configparser.ConfigParser(interpolation=None)
        parser.read(config)
        expected = {'host': HOST, 'port': '3080', 'console_start_port_range': '5000',
                    'console_end_port_range': '10000', 'auto_start': 'False'}
        if not parser.has_section('Server') or any(parser['Server'].get(k) != v for k, v in expected.items()):
            raise Error(f'Check host, port, console range and auto_start in {config}; no configuration changed.')
        if self.dry:
            executable = shutil.which('gns3server') or 'gns3server'
        else:
            self.device()
            state = run(['systemctl', '--user', 'is-active', UNIT], check=False)
            if state == 'active':
                print('Managed GNS3 service is already active.')
                return
            if run(['ss', '-H', '-ltn', 'sport = :3080']):
                raise Error('TCP 3080 is occupied; stop the existing server yourself first.')
            executable = shutil.which('gns3server')
            if not executable:
                raise Error('gns3server is not installed.')
        self.change(['systemd-run', '--user', '--collect', '--unit', UNIT,
                     '--service-type=exec', '--property=KillMode=mixed',
                     '--property=TimeoutStopSec=120', executable, '--config', str(config),
                     '--host', HOST, '--port', '3080'])
        if self.dry:
            return
        for _ in range(15):
            if run(['systemctl', '--user', 'is-active', UNIT], check=False) != 'active':
                break
            listeners = run(['ss', '-H', '-ltn', 'sport = :3080'])
            if f'{HOST}:3080' in listeners:
                print(f'GNS3 listening on {HOST}:3080.')
                return
            time.sleep(1)
        self.change(['systemctl', '--user', 'stop', UNIT])
        raise Error('GNS3 did not become ready; inspect labctl.py logs.')

    def dispatch(self, action):
        if action == 'up':
            self.network_start()
            try:
                self.ports('allow')
                self.server_start()
            except Exception:
                self.ports('deny')
                raise
        elif action == 'down':
            self.dispatch('server-stop')
            self.ports('deny')
            self.dispatch('network-stop')
        elif action == 'network-start':
            self.network_start()
        elif action == 'network-stop':
            if not self.dry and run(['systemctl', '--user', 'is-active', UNIT], check=False) in ('active', 'activating', 'deactivating'):
                raise Error('Stop the managed GNS3 server before stopping ZeroTier.')
            self.change(['sudo', 'systemctl', 'stop', 'zerotier-one'])
        elif action == 'server-start':
            self.server_start()
        elif action == 'server-stop':
            if not self.dry:
                loaded = run(['systemctl', '--user', 'show', UNIT, '--property=LoadState', '--value'])
                if loaded == 'not-found':
                    print('Managed GNS3 unit is already absent.')
                    return
                if loaded != 'loaded':
                    raise Error(f'Unexpected GNS3 unit load state: {loaded}')
            self.change(['systemctl', '--user', 'stop', UNIT])
        elif action.startswith('ports-'):
            self.ports('allow' if action == 'ports-open' else 'deny')
        elif action == 'logs':
            print(run(['journalctl', '--user', '-u', UNIT, '-n', '60', '--no-pager']))
        else:
            for args in (['systemctl', 'is-active', 'zerotier-one'],
                         ['systemctl', '--user', 'is-active', UNIT],
                         ['ip', '-brief', '-4', 'address'], ['ss', '-H', '-ltn', 'sport = :3080']):
                print(shlex.join(args) + '\n' + run(args, check=False))
            print(self.firewall_status())


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--dry-run', action='store_true', help='Print mutations; local inspection still runs.')
    parser.add_argument('--interface', help='ZeroTier device, useful for preview or closing while offline.')
    parser.add_argument('action', choices=ACTIONS)
    args = parser.parse_args(argv)
    if os.geteuid() == 0:
        raise Error('Run as your desktop user without sudo; privileged commands request sudo separately.')
    Controller(args.interface, args.dry_run).dispatch(args.action)
    return 0


if __name__ == '__main__':
    try:
        # Serialize invocations without writing project state or PID files.
        with open(f'/run/user/{os.getuid()}/eval1-labctl.lock', 'a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            sys.exit(main())
    except (Error, OSError, ValueError, subprocess.SubprocessError) as exc:
        print(f'Error: {exc}', file=sys.stderr)
        sys.exit(1)
