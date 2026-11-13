<?php
// Shared helpers to configure OPNsense through its own MVC models (validated) and the legacy config tree.
require_once 'config.inc';
require_once 'util.inc';
require_once '/usr/local/opnsense/mvc/script/load_phalcon.php';
use OPNsense\Core\Config;

function cfg() { return Config::getInstance()->object(); }

function add_item($container, array $values) {
    $node = $container->Add();
    $node->setNodes($values);
    return $node->getAttributes()['uuid'];
}

function persist($mdl, $label) {
    $msgs = $mdl->performValidation();
    if (count($msgs) > 0) {
        foreach ($msgs as $m) echo "INVALID {$label}: " . $m->getField() . ': ' . $m->getMessage() . "\n";
        exit(1);
    }
    $mdl->serializeToConfig();
    echo "OK {$label}\n";
}

function set_root_password($plain) {
    foreach (cfg()->system->user as $u) {
        if ((string)$u->name == 'root') $u->password = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 11]);
    }
}

function set_ssh($ifaces) {
    $ssh = cfg()->system->ssh;
    $ssh->enabled = 'enabled';
    $ssh->permitrootlogin = '1';
    $ssh->passwordauth = '1';
    $ssh->interfaces = $ifaces;
}

function set_iface($name, array $values, array $remove = []) {
    $i = cfg()->interfaces;
    if (!isset($i->$name)) $i->addChild($name);
    foreach ($values as $k => $v) $i->$name->$k = $v;
    foreach ($remove as $k) unset($i->$name->$k);
}

function clear_filter_rules($filter) {
    foreach ($filter->rules->rule->iterateItems() as $uuid => $rule) $filter->rules->rule->del($uuid);
}

function clear_dhcp_ranges($dns) {
    foreach ($dns->dhcp_ranges->iterateItems() as $uuid => $r) $dns->dhcp_ranges->del($uuid);
}
