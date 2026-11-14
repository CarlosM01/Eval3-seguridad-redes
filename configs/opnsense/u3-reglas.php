<?php
// U3 (CI3062, Fase 2, tareas L1 y L2) on FW-ZONA-CENTRO. Idempotent.
//  L1: every pass rule logs; least privilege (VLAN 10 <-> vehicle rules disabled, VLAN 10 Internet without HTTP 80);
//      final "block + log" rule (R-99) on every interface; each rule description starts with its R-xx code.
//  L2: schedule HORARIO_DESPACHO (Mon-Sat 07:00-20:00 Chile = 10:00-23:00 UTC; the firewall clock is UTC) on the
//      vehicle -> WMS rule, plus aliases WMS_ODOO, PUERTOS_WMS and PUERTOS_SALIDA_V10.
// Run: php /tmp/opn/u3-reglas.php, then: configctl filter reload && configctl filter refresh_aliases
// Backup taken before: /conf/backup/config-pre-u3.xml
require_once '/tmp/opn/lib.php';
use OPNsense\Core\Config;

// --- L2: schedule (legacy config tree, the Filter model reads it by name) ---
$c = cfg();
if (!isset($c->schedules)) $c->addChild('schedules');
$have = false;
foreach ($c->schedules->children() as $s) if ((string)$s->name == 'HORARIO_DESPACHO') $have = true;
if (!$have) {
    $s = $c->schedules->addChild('schedule');
    $s->addChild('name', 'HORARIO_DESPACHO');
    $s->addChild('descr', 'Turno de despacho: lunes a sabado 07:00-20:00 Chile (10:00-23:00 UTC)');
    $t = $s->addChild('timerange');
    $t->addChild('position', '1,2,3,4,5,6');
    $t->addChild('hour', '10:00-23:00');
    $t->addChild('rangedescr', 'Lunes a sabado');
}

// --- Aliases (objects) ---
$al = new OPNsense\Firewall\Alias();
$want = [
    ['WMS_ODOO', 'host', '192.168.20.5', 'WMS Odoo (VLAN 20)'],
    ['PUERTOS_WMS', 'port', '8069', 'Puerto del WMS (8069 hasta que T2.2 lo pase a 443)'],
    ['PUERTOS_SALIDA_V10', 'port', "53\n123\n443", 'Salida a Internet de la VLAN 10: DNS, NTP y HTTPS (sin HTTP 80)'],
];
foreach ($want as [$name, $type, $content, $descr]) {
    $found = false;
    foreach ($al->aliases->alias->iterateItems() as $a) if ((string)$a->name == $name) $found = true;
    if (!$found) add_item($al->aliases->alias, ['enabled' => '1', 'name' => $name, 'type' => $type, 'content' => $content,
                                                'description' => $descr]);
}
persist($al, 'aliases');

// --- L1: rules ---
$f = new OPNsense\Firewall\Filter();
// [sequence, interface] => [code, description, extra fields]
$map = [
    '25|opt1'  => ['R-01', 'VLAN 10 hacia el WMS Odoo', ['destination_net' => 'WMS_ODOO', 'destination_port' => 'PUERTOS_WMS']],
    '210|enc0' => ['R-02', 'Vehiculo (VPN) hacia el WMS, solo en horario de despacho',
                   ['destination_net' => 'WMS_ODOO', 'destination_port' => 'PUERTOS_WMS', 'sched' => 'HORARIO_DESPACHO']],
    '70|lan'   => ['R-03', 'VLAN 99 administra VLAN 10/20/30 (SSH, SNMP, HTTPS)', []],
    '80|lan'   => ['R-03', 'VLAN 99 ping a VLAN 10/20/30', []],
    '90|lan'   => ['R-03', 'VLAN 99 hacia Wazuh (dashboard, API, indexer)', []],
    '20|opt1'  => ['R-04', 'VLAN 10 envia logs y agente al SIEM', []],
    '50|opt2'  => ['R-04', 'VLAN 20 envia logs y agente al SIEM', []],
    '30|opt1'  => ['R-05', 'VLAN 10 sale a Internet: DNS, NTP y HTTPS', ['destination_port' => 'PUERTOS_SALIDA_V10']],
    '40|opt1'  => ['R-05', 'VLAN 10 ping a Internet', []],
    '300|wan'  => ['R-06', 'IPsec: IKE desde cualquier origen (vehiculo tras CGNAT)', []],
    '310|wan'  => ['R-06', 'IPsec: NAT-T', []],
    '320|wan'  => ['R-06', 'IPsec: ESP', []],
    '95|opt1'  => ['R-08', 'VLAN 10 hacia PKI-CA (CRL y OCSP)', []],
    '95|opt2'  => ['R-08', 'VLAN 20 hacia PKI-CA (CRL y OCSP)', []],
    '95|opt3'  => ['R-08', 'VLAN 30 hacia PKI-CA (CRL y OCSP)', []],
    '60|opt3'  => ['R-09', 'VLAN 30: DNS y NTP solo hacia su gateway', []],
];
$disable = [
    '10|opt1'  => 'VLAN 10 hacia el vehiculo',
    '200|enc0' => 'Vehiculo hacia la VLAN 10',
];
foreach ($f->rules->rule->iterateItems() as $uuid => $r) {
    $key = (string)$r->sequence . '|' . (string)$r->interface;
    if (isset($map[$key])) {
        [$code, $descr, $extra] = $map[$key];
        $r->setNodes(array_merge(['log' => '1', 'enabled' => '1', 'description' => "{$code} {$descr}"], $extra));
    } elseif (isset($disable[$key])) {
        $r->setNodes(['enabled' => '0', 'log' => '1',
                      'description' => "Deshabilitada U3 (minimo privilegio, informe U2 C2): {$disable[$key]}"]);
    }
}
// R-99: final block + log on every interface (explicit default deny, so blocked traffic carries a rule label).
$ifs = ['opt1' => 'VLAN 10', 'opt2' => 'VLAN 20', 'opt3' => 'VLAN 30', 'lan' => 'VLAN 99', 'enc0' => 'IPsec', 'wan' => 'WAN'];
foreach ($ifs as $if => $label) {
    $exists = false;
    foreach ($f->rules->rule->iterateItems() as $r) {
        if ((string)$r->interface == $if && (string)$r->sequence == '9000') $exists = true;
    }
    if (!$exists) {
        add_item($f->rules->rule, ['enabled' => '1', 'action' => 'block', 'quick' => '1', 'direction' => 'in',
                                   'ipprotocol' => 'inet', 'protocol' => 'any', 'sequence' => '9000', 'interface' => $if,
                                   'source_net' => 'any', 'destination_net' => 'any', 'log' => '1',
                                   'description' => "R-99 Denegacion final con registro ({$label})"]);
    }
}
persist($f, 'filter');
Config::getInstance()->save();
echo "SAVED\n";
