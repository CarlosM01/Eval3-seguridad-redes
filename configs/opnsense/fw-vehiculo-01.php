<?php
require_once '/tmp/opn/lib.php';
use OPNsense\Core\Config;

// --- System -------------------------------------------------------------
$c = cfg();
$c->system->hostname = 'FW-VEHICULO-01';
$c->system->domain = 'empresa.local';
unset($c->trigger_initial_wizard);
set_root_password('test123');
set_ssh('lan');

// --- Interfaces: WAN by DHCP from the carrier, LAN for the driver's devices
set_iface('wan', ['descr' => 'WAN_MOVIL', 'ipaddrv6' => ''], ['blockpriv', 'blockbogons']);  // carrier uses 100.64/10 (CGNAT)
set_iface('lan', ['descr' => 'LAN_VEHICULO', 'ipaddr' => '172.16.50.1', 'subnet' => '24', 'ipaddrv6' => ''],
          ['subnetv6', 'track6-interface', 'track6-prefix-id']);

// --- DHCP for the vehicle LAN ------------------------------------------
$dns = new OPNsense\Dnsmasq\Dnsmasq();
$dns->interface = 'lan';
$dns->dhcp->enable_ra = '0';
$dns->dhcp->domain = 'empresa.local';
clear_dhcp_ranges($dns);
add_item($dns->dhcp_ranges, ['interface' => 'lan', 'start_addr' => '172.16.50.100', 'end_addr' => '172.16.50.200',
                             'description' => 'Dispositivos del conductor']);
persist($dns, 'dhcp');

// --- Firewall: keep the default "LAN to any" IPv4 rule, drop the IPv6 one --
$f = new OPNsense\Firewall\Filter();
foreach ($f->rules->rule->iterateItems() as $uuid => $r) {
    if ((string)$r->ipprotocol == 'inet6') $f->rules->rule->del($uuid);
}
persist($f, 'filter');

// --- IPsec: initiator towards the central site --------------------------
$ip = new OPNsense\IPsec\IPsec();
// IPsec is enabled by ipsec-enable.php (legacy ipsec.enable node).
add_item($ip->preSharedKeys->preSharedKey, ['ident' => 'vehiculo-01.empresa.local', 'remote_ident' => 'fw-centro.empresa.local',
                                            'keyType' => 'PSK', 'Key' => 'test123', 'description' => 'VPN hacia la sede central']);
persist($ip, 'ipsec-psk');
$sw = new OPNsense\IPsec\Swanctl();
$conn = add_item($sw->Connections->Connection, ['enabled' => '1', 'version' => '2', 'remote_addrs' => '203.0.113.2',
                                                 'dpd_delay' => '30', 'dpd_timeout' => '120', 'keyingtries' => '0',
                                                 'description' => 'VPN-SEDE-CENTRAL (initiator)']);
add_item($sw->locals->local, ['connection' => $conn, 'auth' => 'psk', 'id' => 'vehiculo-01.empresa.local', 'description' => 'FW-VEHICULO-01']);
add_item($sw->remotes->remote, ['connection' => $conn, 'auth' => 'psk', 'id' => 'fw-centro.empresa.local', 'description' => 'FW-ZONA-CENTRO']);
add_item($sw->children->child, ['connection' => $conn, 'local_ts' => '172.16.50.0/24', 'remote_ts' => '192.168.10.0/24',
                                'start_action' => 'trap|start', 'dpd_action' => 'start', 'close_action' => 'start',
                                'description' => 'LAN vehiculo <-> VLAN 10']);
persist($sw, 'ipsec-connection');

Config::getInstance()->save();
echo "SAVED\n";
