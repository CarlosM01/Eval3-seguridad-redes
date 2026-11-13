<?php
// Wazuh moved to three Docker nodes: SIEM_WAZUH now lists manager, indexer and dashboard.
// Run on FW-ZONA-CENTRO, then: configctl filter reload
require_once 'config.inc';
require_once 'util.inc';
require_once '/usr/local/opnsense/mvc/script/load_phalcon.php';
use OPNsense\Core\Config;

$a = new OPNsense\Firewall\Alias();
$found = false;
foreach ($a->aliases->alias->iterateItems() as $uuid => $alias) {
    if ((string)$alias->name == 'SIEM_WAZUH') {
        $alias->content = "192.168.30.5\n192.168.30.6\n192.168.30.7";
        $alias->description = 'Wazuh en Docker: manager .5, indexer .6, dashboard .7';
        $found = true;
    }
}
if (!$found) { echo "SIEM_WAZUH not found\n"; exit(1); }
$msgs = $a->performValidation();
if (count($msgs) > 0) {
    foreach ($msgs as $m) echo 'INVALID alias: ' . $m->getField() . ': ' . $m->getMessage() . "\n";
    exit(1);
}
$a->serializeToConfig();
Config::getInstance()->save();
echo "ALIAS_OK\n";
