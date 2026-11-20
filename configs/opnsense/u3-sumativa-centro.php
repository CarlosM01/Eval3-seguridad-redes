<?php
// U3 summative (CI3062, case 2) on FW-ZONA-CENTRO: final rule set of the report table R-01..R-99. Idempotent.
//  - DMZ: opt4 = vlan040 (VLAN 40, 192.168.40.1/24); B2B gateway 192.168.40.10.
//  - Aliases WMS_ODOO, BD_POSTGRES, SIEM_WAZUH, PKI_CA, DMZ_B2B_SRV, RED_VEHICULO, POOL_VPN_REMOTA (+ port aliases).
//  - Schedule HORARIO_DESPACHO (Mon-Sat 07:00-20:00 Chile = 10:00-23:00 UTC; the firewall clock is UTC).
//  - Filter rules are rebuilt from scratch: every rule logs, R-99 closes each interface.
//  - NAT: port forward WAN 443 -> DMZ_B2B_SRV:443 (R-05A); hybrid outbound NAT with an explicit VLAN 10 rule (R-07).
// Run: php /tmp/opn/u3-sumativa-centro.php, then: configctl filter reload && configctl filter refresh_aliases
// Backup taken before: /conf/backup/config-pre-u3-sumativa.xml
require_once '/tmp/opn/lib.php';
use OPNsense\Core\Config;

// --- DMZ interface (a teammate had left opt4 "DMZ" on vtnet0, disabled) ---
set_iface('opt4', ['if' => 'vlan040', 'descr' => 'DMZ', 'enable' => '1', 'ipaddr' => '192.168.40.1', 'subnet' => '24']);

// --- Schedule (legacy tree, the Filter model reads it by name) ---
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

// --- Aliases ---
$al = new OPNsense\Firewall\Alias();
$want = [
    ['WMS_ODOO', 'host', '192.168.20.5', 'WMS Odoo (VLAN 20)'],
    ['BD_POSTGRES', 'host', '192.168.20.4', 'Base de datos PostgreSQL del WMS (VLAN 20)'],
    ['SIEM_WAZUH', 'host', '192.168.30.5', 'Wazuh manager: syslog 514 y agentes 1514/1515'],
    ['PKI_CA', 'host', '10.99.0.10', 'CA emisora: CRL (80) y OCSP (2560)'],
    ['DMZ_B2B_SRV', 'host', '192.168.40.10', 'Pasarela B2B en la DMZ (VLAN 40)'],
    ['RED_VEHICULO', 'network', '172.16.50.0/24', 'LAN del vehiculo (tunel IPsec)'],
    ['POOL_VPN_REMOTA', 'network', '10.8.0.0/24', 'Pool de clientes OpenVPN (acceso remoto)'],
    ['RED_MGMT', 'network', '10.99.0.0/25', 'VLAN 99 MGMT_TI'],
    ['PUERTOS_WMS', 'port', '8069', 'WMS Odoo'],
    ['PUERTOS_SIEM', 'port', "514\n1514\n1515", 'Ingesta del SIEM: syslog y agentes Wazuh'],
    ['PUERTOS_PKI', 'port', "80\n2560", 'CRL y OCSP'],
    ['PUERTOS_SALIDA_V10', 'port', "53\n123\n443", 'Salida de la VLAN 10: DNS, NTP y HTTPS (sin HTTP 80)'],
    ['PUERTOS_ADMIN', 'port', "22\n161\n443\n5601\n9200\n55000", 'Administracion: SSH, SNMP, HTTPS, Wazuh'],
];
foreach ($want as [$name, $type, $content, $descr]) {
    $node = null;
    foreach ($al->aliases->alias->iterateItems() as $a) if ((string)$a->name == $name) $node = $a;
    $vals = ['enabled' => '1', 'name' => $name, 'type' => $type, 'content' => $content, 'description' => $descr];
    if ($node) $node->setNodes($vals); else add_item($al->aliases->alias, $vals);
}
persist($al, 'aliases');

// --- Filter rules ---
$f = new OPNsense\Firewall\Filter();
clear_filter_rules($f);
$base = ['enabled' => '1', 'quick' => '1', 'direction' => 'in', 'ipprotocol' => 'inet', 'log' => '1',
         'source_net' => 'any', 'destination_net' => 'any', 'protocol' => 'any'];
// [sequence, interface, action, protocol, source, destination, port, description, extra]
$rules = [
    // VLAN 10 ADMIN_LOGISTICA (P-01)
    [100, 'opt1', 'pass',  'TCP', 'opt1', 'WMS_ODOO', 'PUERTOS_WMS', 'R-01 VLAN 10 hacia el WMS Odoo (F-01)'],
    [110, 'opt1', 'block', 'TCP', 'opt1', 'BD_POSTGRES', '5432', 'R-01B Bloqueo explicito de la BD desde bodega (F-01)'],
    [120, 'opt1', 'pass',  'TCP/UDP', 'opt1', 'SIEM_WAZUH', 'PUERTOS_SIEM', 'R-06 VLAN 10 envia eventos al SIEM (F-06)'],
    [130, 'opt1', 'pass',  'TCP', 'opt1', 'PKI_CA', 'PUERTOS_PKI', 'R-08 VLAN 10 valida certificados en PKI-CA (F-08)'],
    [140, 'opt1', 'pass',  'TCP/UDP', 'opt1', 'opt1ip', '53', 'R-07 VLAN 10 DNS hacia el firewall (F-07)'],
    [150, 'opt1', 'pass',  'TCP/UDP', 'opt1', 'RFC1918', 'PUERTOS_SALIDA_V10', 'R-07 VLAN 10 sale a Internet: DNS, NTP y HTTPS (F-07)', ['destination_not' => '1']],
    // VLAN 20 INVENTARIO_BODEGAS
    [200, 'opt2', 'pass',  'TCP/UDP', 'opt2', 'SIEM_WAZUH', 'PUERTOS_SIEM', 'R-06 VLAN 20 envia eventos al SIEM (F-06)'],
    [210, 'opt2', 'pass',  'TCP', 'opt2', 'PKI_CA', 'PUERTOS_PKI', 'R-08 VLAN 20 valida certificados en PKI-CA (F-08)'],
    // VLAN 30 SERVIDORES_SIEM
    [300, 'opt3', 'pass',  'TCP', 'opt3', 'PKI_CA', 'PUERTOS_PKI', 'R-08 VLAN 30 valida certificados en PKI-CA (F-08)'],
    // DMZ VLAN 40 (P-05)
    [400, 'opt4', 'pass',  'TCP', 'DMZ_B2B_SRV', 'WMS_ODOO', 'PUERTOS_WMS', 'R-05B DMZ hacia el WMS, solo TCP 8069 (F-05)'],
    [410, 'opt4', 'pass',  'TCP/UDP', 'opt4', 'SIEM_WAZUH', 'PUERTOS_SIEM', 'R-06 DMZ envia eventos al SIEM (F-06)'],
    // VLAN 99 MGMT_TI (P-04)
    [500, 'lan',  'pass',  'TCP/UDP', 'lan', 'RFC1918', 'PUERTOS_ADMIN', 'R-04 VLAN 99 administra la infraestructura (F-04)'],
    [510, 'lan',  'pass',  'ICMP', 'lan', 'RFC1918', '', 'R-04 VLAN 99 ping de diagnostico (F-04)'],
    [520, 'lan',  'pass',  'TCP/UDP', 'lan', 'SIEM_WAZUH', 'PUERTOS_SIEM', 'R-06 VLAN 99 envia eventos al SIEM (F-06)'],
    // IPsec (P-02)
    [600, 'enc0', 'pass',  'TCP', 'RED_VEHICULO', 'WMS_ODOO', 'PUERTOS_WMS', 'R-02 Vehiculo hacia el WMS solo en HORARIO_DESPACHO (F-02)', ['sched' => 'HORARIO_DESPACHO']],
    // OpenVPN (P-03)
    [700, 'openvpn', 'pass',  'TCP', 'POOL_VPN_REMOTA', 'WMS_ODOO', 'PUERTOS_WMS', 'R-03A Supervisor remoto hacia el WMS (F-03)'],
    [710, 'openvpn', 'block', 'any', 'POOL_VPN_REMOTA', 'RED_MGMT', '', 'R-03B Bloqueo de la VLAN 99 desde la VPN remota (F-03)'],
    [720, 'openvpn', 'block', 'any', 'POOL_VPN_REMOTA', 'BD_POSTGRES', '', 'R-03B Bloqueo de la BD desde la VPN remota (F-03)'],
    // WAN
    [800, 'wan',  'pass',  'TCP', 'any', 'DMZ_B2B_SRV', '443', 'R-05A Publicacion B2B: HTTPS hacia la DMZ por NAT (F-05)'],
    [810, 'wan',  'pass',  'UDP', 'any', 'wanip', '500', 'R-02 IPsec: IKE desde la red movil (F-02)'],
    [820, 'wan',  'pass',  'UDP', 'any', 'wanip', '4500', 'R-02 IPsec: NAT-T (F-02)'],
    [830, 'wan',  'pass',  'ESP', 'any', 'wanip', '', 'R-02 IPsec: ESP (F-02)'],
    [840, 'wan',  'pass',  'UDP', 'any', 'wanip', '1194', 'R-03A OpenVPN: acceso remoto UDP 1194 (F-03)'],
];
foreach ($rules as $r) {
    [$seq, $if, $act, $proto, $src, $dst, $port, $descr] = $r;
    $extra = $r[8] ?? [];
    add_item($f->rules->rule, array_merge($base, ['sequence' => (string)$seq, 'interface' => $if, 'action' => $act,
        'protocol' => $proto, 'source_net' => $src, 'destination_net' => $dst, 'destination_port' => $port,
        'description' => $descr], $extra));
}
$ifs = ['opt1' => 'VLAN 10', 'opt2' => 'VLAN 20', 'opt3' => 'VLAN 30', 'opt4' => 'DMZ', 'lan' => 'VLAN 99',
        'enc0' => 'IPsec', 'openvpn' => 'OpenVPN', 'wan' => 'WAN'];
foreach ($ifs as $if => $label) {
    add_item($f->rules->rule, array_merge($base, ['sequence' => '9000', 'interface' => $if, 'action' => 'block',
        'description' => "R-99 Denegacion final con registro ({$label})"]));
}
// Outbound NAT (hybrid): explicit rule for VLAN 10 so it shows in the GUI; automatic rules still cover the rest.
foreach ($f->snatrules->rule->iterateItems() as $uuid => $r) $f->snatrules->rule->del($uuid);
add_item($f->snatrules->rule, ['enabled' => '1', 'sequence' => '100', 'interface' => 'wan', 'ipprotocol' => 'inet',
    'protocol' => 'any', 'source_net' => '192.168.10.0/24', 'destination_net' => 'any', 'target' => 'wanip', 'log' => '1',
    'description' => 'R-07 NAT de salida: VLAN 10 sale como 203.0.113.2 (F-07)']);
persist($f, 'filter');
cfg()->nat->outbound->mode = 'hybrid';

// --- Port forward WAN 443 -> DMZ (R-05A); the filter rule above lets it through with a log entry ---
$d = new OPNsense\Firewall\DNat();
foreach ($d->rule->iterateItems() as $uuid => $r) $d->rule->del($uuid);
add_item($d->rule, ['interface' => 'wan', 'ipprotocol' => 'inet', 'protocol' => 'tcp',
    'source' => ['network' => 'any'], 'destination' => ['network' => 'wanip', 'port' => '443'],
    'target' => '192.168.40.10', 'local-port' => '443', 'log' => '1',
    'descr' => 'R-05A Publicacion B2B: 203.0.113.2:443 -> 192.168.40.10:443 (F-05)']);
persist($d, 'dnat');

Config::getInstance()->save();
echo "SAVED\n";
