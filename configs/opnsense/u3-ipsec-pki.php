<?php
// U3 summative / hardening T2.1: IPsec VPN-VEHICULO-01 moves from PSK to X.509 certificates issued by PKI-CA.
// Run on each firewall:  php /tmp/opn/u3-ipsec-pki.php <centro|vehiculo>
// Needs in /tmp/opn: raiz.pem, emisora.pem, <name>.pem; and the private key /root/<name>.key (generated on the
// firewall, only its CSR left the device). Idempotent. Afterwards: configctl ipsec reload (or restart).
//  - Trust: CA "Empresa CA Raiz", CA "Empresa CA Emisora", certificate <name>.empresa.local with its key.
//  - Phase 1: local and remote auth = pubkey (certificate), remote must chain to the issuing CA.
//  - Proposals: IKE aes256gcm16-sha256-ecp521, ESP aes256gcm16-ecp521 (the model lists no ECP-256 option).
//  - Phase 2: only the WMS Child SA (192.168.20.5/32 <-> 172.16.50.0/24); the VLAN 10 Child SA is deleted.
require_once '/tmp/opn/lib.php';
use OPNsense\Core\Config;

$role = $argv[1] ?? '';
$name = ['centro' => 'fw-centro', 'vehiculo' => 'vehiculo-01'][$role] ?? null;
if (!$name) { echo "usage: u3-ipsec-pki.php <centro|vehiculo>\n"; exit(1); }
$peer = $role == 'centro' ? 'vehiculo-01.empresa.local' : 'fw-centro.empresa.local';

function pem_b64($file) { return base64_encode(trim(file_get_contents($file)) . "\n"); }

// --- Trust store ---
$ca = new OPNsense\Trust\Ca();
$refs = [];
foreach (['raiz' => 'Empresa CA Raiz', 'emisora' => 'Empresa CA Emisora'] as $f => $descr) {
    $crt = pem_b64("/tmp/opn/{$f}.pem");
    $node = null;
    foreach ($ca->ca->iterateItems() as $c) if ((string)$c->descr == $descr) $node = $c;
    if (!$node) { $node = $ca->ca->Add(); $node->refid = uniqid(); }
    // The issuing CA points to the root (caref) so OpenVPN and strongSwan get the full chain.
    $node->setNodes(['descr' => $descr, 'crt' => $crt] + ($f == 'emisora' ? ['caref' => $refs['raiz']] : []));
    $refs[$f] = (string)$node->refid;
}
persist($ca, 'ca');
$ce = new OPNsense\Trust\Cert();
$cdescr = "{$name}.empresa.local (PKI-CA)";
$node = null;
foreach ($ce->cert->iterateItems() as $c) if ((string)$c->descr == $cdescr) $node = $c;
if (!$node) { $node = $ce->cert->Add(); $node->refid = uniqid(); }
$node->setNodes(['descr' => $cdescr, 'caref' => $refs['emisora'], 'crt' => pem_b64("/tmp/opn/{$name}.pem"),
                 'prv' => pem_b64("/root/{$name}.key")]);
$certref = (string)$node->refid;
persist($ce, 'cert');

// --- IPsec (swanctl model) ---
$sw = new OPNsense\IPsec\Swanctl();
foreach ($sw->Connections->Connection->iterateItems() as $c) {
    $c->proposals = 'aes256gcm16-sha256-ecp521';
    $c->description = 'VPN-VEHICULO-01 (certificados PKI-CA)' . ($role == 'centro' ? ' (responder)' : '');
}
foreach ($sw->locals->local->iterateItems() as $l) $l->setNodes(['auth' => 'pubkey', 'certs' => $certref]);
foreach ($sw->remotes->remote->iterateItems() as $r) $r->setNodes(['auth' => 'pubkey', 'id' => $peer, 'cacerts' => $refs['emisora']]);
foreach ($sw->children->child->iterateItems() as $uuid => $ch) {
    $ts = (string)$ch->local_ts . ' ' . (string)$ch->remote_ts;
    if (strpos($ts, '192.168.10.0/24') !== false) { $sw->children->child->del($uuid); echo "DELETED child {$ts}\n"; continue; }
    $ch->esp_proposals = 'aes256gcm16-ecp521';
}
persist($sw, 'swanctl');
Config::getInstance()->save();
echo "SAVED cert={$certref}\n";
