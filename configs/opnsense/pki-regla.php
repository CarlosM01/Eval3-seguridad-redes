<?php
// T1.2 (informe-hardening): rule 95, internal VLANs 10/20/30 -> PKI-CA (10.99.0.10) TCP 80 (CRL, CA
// certificates) and 2560 (OCSP). VLAN 99 needs no rule (same segment). Idempotent.
// Run on FW-ZONA-CENTRO: php /tmp/opn/pki-regla.php, then: configctl filter reload
require_once '/tmp/opn/lib.php';
use OPNsense\Core\Config;

$al = new OPNsense\Firewall\Alias();
$want = [
    ['PKI_CA', 'host', '10.99.0.10', 'PKI-CA: CA emisora, CRL y OCSP'],
    ['PUERTOS_PKI', 'port', "80\n2560", 'CRL y certificados de CA (HTTP) y OCSP'],
];
foreach ($want as [$name, $type, $content, $descr]) {
    $found = false;
    foreach ($al->aliases->alias->iterateItems() as $a) {
        if ((string)$a->name == $name) $found = true;
    }
    if (!$found) {
        add_item($al->aliases->alias, ['enabled' => '1', 'name' => $name, 'type' => $type, 'content' => $content, 'description' => $descr]);
    }
}
persist($al, 'aliases');

$f = new OPNsense\Firewall\Filter();
foreach (['opt1' => 'VLAN 10', 'opt2' => 'VLAN 20', 'opt3' => 'VLAN 30'] as $if => $label) {
    $exists = false;
    foreach ($f->rules->rule->iterateItems() as $r) {
        if ((string)$r->interface == $if && (string)$r->destination_net == 'PKI_CA') $exists = true;
    }
    if (!$exists) {
        add_item($f->rules->rule, ['enabled' => '1', 'action' => 'pass', 'quick' => '1', 'direction' => 'in', 'ipprotocol' => 'inet',
                                   'protocol' => 'TCP', 'sequence' => '95', 'interface' => $if, 'source_net' => $if,
                                   'destination_net' => 'PKI_CA', 'destination_port' => 'PUERTOS_PKI',
                                   'description' => "Regla 95: {$label} hacia PKI-CA (CRL y OCSP)"]);
    }
}
persist($f, 'filter');
Config::getInstance()->save();
echo "SAVED\n";
