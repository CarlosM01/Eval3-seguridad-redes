<?php
require_once '/tmp/opn/lib.php';
use OPNsense\Core\Config;

// --- System -------------------------------------------------------------
$c = cfg();
$c->system->hostname = 'FW-ZONA-CENTRO';
$c->system->domain = 'empresa.local';
unset($c->trigger_initial_wizard);
set_root_password('test123');
set_ssh('lan');                       // criterion 5: SSH only from VLAN 99

// --- Interfaces (legacy tree) -----------------------------------------
set_iface('wan', ['descr' => 'WAN', 'ipaddr' => '203.0.113.2', 'subnet' => '30', 'gateway' => 'WAN_GW', 'ipaddrv6' => ''],
          ['blockpriv', 'blockbogons']);     // the lab "Internet" uses TEST-NET ranges, which are bogons
set_iface('lan', ['descr' => 'MGMT_TI', 'if' => 'vlan099', 'ipaddr' => '10.99.0.1', 'subnet' => '25', 'ipaddrv6' => ''],
          ['subnetv6', 'track6-interface', 'track6-prefix-id']);
set_iface('opt1', ['descr' => 'ADMIN_LOGISTICA', 'if' => 'vlan010', 'enable' => '1', 'ipaddr' => '192.168.10.1', 'subnet' => '24']);
set_iface('opt2', ['descr' => 'INVENTARIO_BODEGAS', 'if' => 'vlan020', 'enable' => '1', 'ipaddr' => '192.168.20.1', 'subnet' => '24']);
set_iface('opt3', ['descr' => 'SERVIDORES_SIEM', 'if' => 'vlan030', 'enable' => '1', 'ipaddr' => '192.168.30.1', 'subnet' => '24']);

// --- VLANs on the trunk to SW-ZONA-CENTRO Gi0/0 ------------------------
$vlan = new OPNsense\Interfaces\Vlan();
foreach ([10 => 'ADMIN_LOGISTICA', 20 => 'INVENTARIO_BODEGAS', 30 => 'SERVIDORES_SIEM', 99 => 'MGMT_TI'] as $tag => $d) {
    add_item($vlan->vlan, ['if' => 'vtnet0', 'tag' => (string)$tag, 'vlanif' => sprintf('vlan0%02d', $tag), 'descr' => $d]);
}
persist($vlan, 'vlans');

// --- Default gateway ---------------------------------------------------
$gw = new OPNsense\Routing\Gateways();
add_item($gw->gateway_item, ['name' => 'WAN_GW', 'interface' => 'wan', 'ipprotocol' => 'inet', 'gateway' => '203.0.113.1',
                             'defaultgw' => '1', 'descr' => 'ISP-WAN Gi0/1']);
persist($gw, 'gateway');

// --- DHCP (Dnsmasq) for VLAN 10 and 20, same as the old IOS pools -------
$dns = new OPNsense\Dnsmasq\Dnsmasq();
$dns->interface = 'opt1,opt2';
$dns->dhcp->enable_ra = '0';
$dns->dhcp->domain = 'empresa.local';
clear_dhcp_ranges($dns);
foreach (['opt1' => '192.168.10', 'opt2' => '192.168.20'] as $if => $net) {
    add_item($dns->dhcp_ranges, ['interface' => $if, 'start_addr' => "$net.11", 'end_addr' => "$net.254",
                                 'description' => "Pool $if (excluye .1-.10)"]);
    add_item($dns->dhcp_options, ['type' => 'set', 'option' => '6', 'interface' => $if, 'value' => '1.1.1.1,8.8.8.8',
                                  'description' => 'DNS publicos, igual que el router IOS']);
}
persist($dns, 'dhcp');

// --- Aliases -------------------------------------------------------------
$al = new OPNsense\Firewall\Alias();
$aliases = [
    ['RFC1918', 'network', "10.0.0.0/8\n172.16.0.0/12\n192.168.0.0/16", 'Redes privadas (lo que no es Internet)'],
    ['REDES_VLAN_10_20_30', 'network', "192.168.10.0/24\n192.168.20.0/24\n192.168.30.0/24", 'VLANs administradas desde la VLAN 99'],
    ['RED_VEHICULO', 'network', '172.16.50.0/24', 'LAN del vehiculo (otro extremo de la VPN)'],
    ['SIEM_WAZUH', 'host', "192.168.30.5\n192.168.30.6\n192.168.30.7", 'Wazuh en Docker: manager .5, indexer .6, dashboard .7'],
    ['PUERTOS_SIEM_AGENTE', 'port', "514\n1514\n1515", 'Syslog y agente Wazuh'],
    ['PUERTOS_INTERNET', 'port', "53\n80\n123\n443", 'DNS, HTTP, NTP y HTTPS'],
    ['PUERTOS_ADMIN', 'port', "22\n161\n443", 'SSH, SNMP y HTTPS'],
    ['PUERTOS_WAZUH', 'port', "443\n514\n1514\n1515\n5601\n9200\n55000", 'Dashboard, indexer, API y agentes Wazuh'],
    ['PUERTOS_DNS_NTP', 'port', "53\n123", 'DNS y NTP'],
];
foreach ($aliases as [$name, $type, $content, $d]) {
    add_item($al->aliases->alias, ['enabled' => '1', 'name' => $name, 'type' => $type, 'content' => $content, 'description' => $d]);
}
persist($al, 'aliases');

// --- Firewall rules: ACL criteria 1-5 + VPN ------------------------------
// Criterion 1 (isolation between VLANs) is the default deny; pf is stateful, so replies need no rule.
$f = new OPNsense\Firewall\Filter();
clear_filter_rules($f);
$seq = 0;
$rule = function (array $r) use ($f, &$seq) {
    $seq += 10;
    add_item($f->rules->rule, array_merge(['enabled' => '1', 'action' => 'pass', 'quick' => '1', 'direction' => 'in',
                                           'ipprotocol' => 'inet', 'protocol' => 'any', 'sequence' => (string)$seq], $r));
};
$rule(['interface' => 'opt1', 'source_net' => 'opt1', 'destination_net' => 'RED_VEHICULO', 'description' => 'VPN: VLAN 10 hacia el vehiculo']);
$rule(['interface' => 'opt1', 'protocol' => 'TCP/UDP', 'source_net' => 'opt1', 'destination_net' => 'SIEM_WAZUH', 'destination_port' => 'PUERTOS_SIEM_AGENTE', 'description' => 'Criterio 2: logs al SIEM']);
$rule(['interface' => 'opt1', 'protocol' => 'TCP/UDP', 'source_net' => 'opt1', 'destination_net' => 'RFC1918', 'destination_not' => '1', 'destination_port' => 'PUERTOS_INTERNET', 'description' => 'Criterio 4: VLAN 10 sale a Internet']);
$rule(['interface' => 'opt1', 'protocol' => 'ICMP', 'source_net' => 'opt1', 'destination_net' => 'RFC1918', 'destination_not' => '1', 'description' => 'Criterio 4: ping a Internet']);
$rule(['interface' => 'opt2', 'protocol' => 'TCP/UDP', 'source_net' => 'opt2', 'destination_net' => 'SIEM_WAZUH', 'destination_port' => 'PUERTOS_SIEM_AGENTE', 'description' => 'Criterio 2: logs al SIEM']);
$rule(['interface' => 'opt3', 'protocol' => 'TCP/UDP', 'source_net' => 'opt3', 'destination_net' => 'opt3ip', 'destination_port' => 'PUERTOS_DNS_NTP', 'description' => 'VLAN 30: DNS y NTP solo hacia su gateway']);
$rule(['interface' => 'lan', 'protocol' => 'TCP/UDP', 'source_net' => 'lan', 'destination_net' => 'REDES_VLAN_10_20_30', 'destination_port' => 'PUERTOS_ADMIN', 'description' => 'Criterio 3: VLAN 99 administra (SSH, SNMP, HTTPS)']);
$rule(['interface' => 'lan', 'protocol' => 'ICMP', 'source_net' => 'lan', 'destination_net' => 'REDES_VLAN_10_20_30', 'description' => 'Criterio 3: VLAN 99 ping']);
$rule(['interface' => 'lan', 'protocol' => 'TCP/UDP', 'source_net' => 'lan', 'destination_net' => 'SIEM_WAZUH', 'destination_port' => 'PUERTOS_WAZUH', 'description' => 'Criterio 3: VLAN 99 hacia Wazuh']);
// The IPsec (enc0) rule is added by enc0-rule.php after the first reboot: enc0 only exists once IPsec is enabled.
persist($f, 'filter');

// --- Syslog to the SIEM --------------------------------------------------
$sl = new OPNsense\Syslog\Syslog();
add_item($sl->destinations->destination, ['enabled' => '1', 'transport' => 'udp4', 'hostname' => '192.168.30.5', 'port' => '514',
                                          'description' => 'SIEM Wazuh']);
persist($sl, 'syslog');

// --- IPsec: responder for the vehicle (any remote address, FQDN + PSK) ---
$ip = new OPNsense\IPsec\IPsec();
// IPsec is enabled by ipsec-enable.php (legacy ipsec.enable node).
add_item($ip->preSharedKeys->preSharedKey, ['ident' => 'fw-centro.empresa.local', 'remote_ident' => 'vehiculo-01.empresa.local',
                                            'keyType' => 'PSK', 'Key' => 'test123', 'description' => 'VPN vehiculo 01']);
persist($ip, 'ipsec-psk');
$sw = new OPNsense\IPsec\Swanctl();
$conn = add_item($sw->Connections->Connection, ['enabled' => '1', 'version' => '2', 'local_addrs' => '203.0.113.2', 'remote_addrs' => '',
                                                 'dpd_delay' => '30', 'dpd_timeout' => '120', 'description' => 'VPN-VEHICULO-01 (responder)']);
add_item($sw->locals->local, ['connection' => $conn, 'auth' => 'psk', 'id' => 'fw-centro.empresa.local', 'description' => 'FW-ZONA-CENTRO']);
add_item($sw->remotes->remote, ['connection' => $conn, 'auth' => 'psk', 'id' => 'vehiculo-01.empresa.local', 'description' => 'FW-VEHICULO-01']);
add_item($sw->children->child, ['connection' => $conn, 'local_ts' => '192.168.10.0/24', 'remote_ts' => '172.16.50.0/24',
                                'start_action' => 'none', 'dpd_action' => 'clear', 'description' => 'VLAN 10 <-> LAN vehiculo']);
persist($sw, 'ipsec-connection');

Config::getInstance()->save();
echo "SAVED\n";
