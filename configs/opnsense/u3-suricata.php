<?php
// U3 summative / hardening T4.1: Suricata in IPS mode (Netmap) on the physical parents vtnet1 (WAN) and vtnet0
// (802.1Q trunk), as the OPNsense manual asks for VLANs. vtnet0 is assigned as opt5 "TRUNK" (no address) only
// so Suricata can select it. ET Open rulesets scan, exploit and web_server; one policy turns their alerts into
// drops; alerts go to syslog (and EVE) so the remote syslog target forwards them to Wazuh.
// Run: php /tmp/opn/u3-suricata.php, then: configctl ids update && configctl ids restart. Idempotent.
// The node needs 4 GB of RAM (raised in GNS3 on 2026-11-20).
require_once '/tmp/opn/lib.php';
use OPNsense\Core\Config;

set_iface('opt5', ['if' => 'vtnet0', 'descr' => 'TRUNK', 'enable' => '1'], ['ipaddr', 'subnet']);
Config::getInstance()->save();

$ids = new OPNsense\IDS\IDS();
$ids->general->setNodes([
    'enabled' => '1', 'mode' => 'netmap', 'interfaces' => 'wan,opt5', 'promisc' => '0',
    'homenet' => "192.168.0.0/16,10.0.0.0/8,172.16.0.0/12,203.0.113.0/30",
    'syslog' => '1', 'syslog_eve' => '1',
]);
$want = ['emerging-scan.rules', 'emerging-exploit.rules', 'emerging-web_server.rules'];
$uuids = [];
foreach ($want as $fn) {
    $node = null;
    foreach ($ids->files->file->iterateItems() as $uuid => $f) if ((string)$f->filename == $fn) { $node = $f; $uuids[] = $uuid; }
    if (!$node) { $uuids[] = add_item($ids->files->file, ['filename' => $fn, 'enabled' => '1']); }
    else $node->enabled = '1';
}
$descr = 'U3 IPS: descartar escaneos y exploits (ET Open)';
$pol = null;
foreach ($ids->policies->policy->iterateItems() as $p) if ((string)$p->description == $descr) $pol = $p;
if (!$pol) $pol = $ids->policies->policy->Add();
$pol->setNodes(['enabled' => '1', 'prio' => '10', 'action' => 'alert', 'rulesets' => implode(',', $uuids),
                'new_action' => 'drop', 'description' => $descr]);
persist($ids, 'ids');
Config::getInstance()->save();
echo "SAVED\n";
