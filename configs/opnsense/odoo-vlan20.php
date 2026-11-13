<?php
// odoo-1 moved from VLAN 10 (192.168.10.4) to VLAN 20 (192.168.20.5).
// Usage: php odoo-vlan20.php centro|vehiculo
//   centro:   drop the 192.168.10.4 -> 192.168.20.4:5432 exception (now intra-VLAN), add a Child SA for the Odoo host
//             only, and rules that let the vehicle LAN (enc0) and VLAN 10 (opt1) reach Odoo on TCP 8069.
//   vehiculo: add the matching Child SA (LAN vehiculo <-> Odoo host).
// The rest of VLAN 20 stays outside the tunnel.
require_once '/tmp/opn/lib.php';
use OPNsense\Core\Config;

const ODOO = '192.168.20.5/32';
const VEHICLE_LAN = '172.16.50.0/24';
$side = $argv[1] ?? '';

function find_connection($sw, $descr_prefix) {
    foreach ($sw->Connections->Connection->iterateItems() as $uuid => $c) {
        if (strpos((string)$c->description, $descr_prefix) === 0) return $uuid;
    }
    echo "MISSING connection {$descr_prefix}\n";
    exit(1);
}

function has_child($sw, $local, $remote) {
    foreach ($sw->children->child->iterateItems() as $c) {
        if ((string)$c->local_ts == $local && (string)$c->remote_ts == $remote) return true;
    }
    return false;
}

$sw = new OPNsense\IPsec\Swanctl();
if ($side === 'centro') {
    $f = new OPNsense\Firewall\Filter();
    foreach ($f->rules->rule->iterateItems() as $uuid => $r) {
        if ((string)$r->source_net == '192.168.10.4' && (string)$r->destination_net == '192.168.20.4') {
            $f->rules->rule->del($uuid);
            echo "DELETED rule odoo(VLAN 10) -> postgres\n";
        }
    }
    // Odoo web (TCP 8069) only: from the vehicle LAN through the tunnel and from VLAN 10 (users).
    $odoo_rules = [
        ['enc0', 'RED_VEHICULO', '210', 'VPN: vehiculo hacia Odoo (VLAN 20, solo 8069)'],
        ['opt1', 'opt1', '25', 'VLAN 10 hacia Odoo (VLAN 20, solo 8069)'],
    ];
    foreach ($odoo_rules as [$if, $src, $seq, $descr]) {
        $exists = false;
        foreach ($f->rules->rule->iterateItems() as $r) {
            if ((string)$r->interface == $if && (string)$r->destination_net == '192.168.20.5') $exists = true;
        }
        if (!$exists) {
            add_item($f->rules->rule, ['enabled' => '1', 'action' => 'pass', 'quick' => '1', 'direction' => 'in', 'ipprotocol' => 'inet',
                                       'protocol' => 'TCP', 'sequence' => $seq, 'interface' => $if, 'source_net' => $src,
                                       'destination_net' => '192.168.20.5', 'destination_port' => '8069', 'description' => $descr]);
        }
    }
    persist($f, 'filter');
    $conn = find_connection($sw, 'VPN-VEHICULO-01');
    if (!has_child($sw, ODOO, VEHICLE_LAN)) {
        add_item($sw->children->child, ['connection' => $conn, 'local_ts' => ODOO, 'remote_ts' => VEHICLE_LAN,
                                        'start_action' => 'none', 'dpd_action' => 'clear', 'description' => 'Odoo (VLAN 20) <-> LAN vehiculo']);
    }
} elseif ($side === 'vehiculo') {
    $conn = find_connection($sw, 'VPN-SEDE-CENTRAL');
    if (!has_child($sw, VEHICLE_LAN, ODOO)) {
        add_item($sw->children->child, ['connection' => $conn, 'local_ts' => VEHICLE_LAN, 'remote_ts' => ODOO,
                                        'start_action' => 'trap|start', 'dpd_action' => 'start', 'close_action' => 'start',
                                        'description' => 'LAN vehiculo <-> Odoo (VLAN 20)']);
    }
} else {
    echo "usage: php odoo-vlan20.php centro|vehiculo\n";
    exit(1);
}
persist($sw, 'ipsec-children');
Config::getInstance()->save();
echo "SAVED\n";
