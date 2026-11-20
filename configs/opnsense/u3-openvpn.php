<?php
// U3 summative / hardening T5.2: OpenVPN remote access (F-03, profile P-03 supervisor) on FW-ZONA-CENTRO.
// Server on WAN 203.0.113.2 UDP 1194, tun, pool 10.8.0.0/24, AES-256-GCM, TLS with PKI-CA certificates
// (server: fw-centro.empresa.local; clients must hold a certificate issued by "Empresa CA Emisora", checked by OCSP).
// Split tunnel: only 192.168.20.5/32 is pushed. Filter rules R-03A/R-03B are set by u3-sumativa-centro.php.
// Needs u3-ipsec-pki.php to have imported the CA and the fw-centro certificate. Idempotent.
// Run: php /tmp/opn/u3-openvpn.php, then: configctl openvpn reconfigure
require_once '/tmp/opn/lib.php';
use OPNsense\Core\Config;

$caref = $certref = null;
foreach ((new OPNsense\Trust\Ca())->ca->iterateItems() as $c) if ((string)$c->descr == 'Empresa CA Emisora') $caref = (string)$c->refid;
foreach ((new OPNsense\Trust\Cert())->cert->iterateItems() as $c) if ((string)$c->descr == 'fw-centro.empresa.local (PKI-CA)') $certref = (string)$c->refid;
if (!$caref || !$certref) { echo "missing CA or certificate, run u3-ipsec-pki.php first\n"; exit(1); }

$ov = new OPNsense\OpenVPN\OpenVPN();
$descr = 'VPN-REMOTA-SUPERVISORES (F-03)';
$node = null;
foreach ($ov->Instances->Instance->iterateItems() as $i) if ((string)$i->description == $descr) $node = $i;
if (!$node) { $node = $ov->Instances->Instance->Add(); $node->vpnid = '1'; }
$node->setNodes([
    'enabled' => '1', 'description' => $descr, 'role' => 'server', 'dev_type' => 'tun', 'proto' => 'udp4',
    'port' => '1194', 'local' => '203.0.113.2', 'topology' => 'subnet', 'server' => '10.8.0.0/24',
    'cert' => $certref, 'ca' => $caref, 'verify_client_cert' => 'require', 'remote_cert_tls' => '1',
    'use_ocsp' => '1', 'auth' => 'SHA256', 'data-ciphers' => 'AES-256-GCM', 'data-ciphers-fallback' => 'AES-256-GCM',
    'push_route' => '192.168.20.5/32', 'keepalive_interval' => '10', 'keepalive_timeout' => '60', 'verb' => '3',
]);
persist($ov, 'openvpn');
Config::getInstance()->save();
echo "SAVED vpnid=" . $node->vpnid . "\n";
