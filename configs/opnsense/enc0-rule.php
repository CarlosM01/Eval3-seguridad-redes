<?php
// Second pass: add a pass rule on the IPsec interface (enc0), which only becomes selectable once IPsec is enabled.
require_once '/tmp/opn/lib.php';
use OPNsense\Core\Config;
[$src, $dst, $descr, $seq] = array_slice($argv, 1, 4);
$f = new OPNsense\Firewall\Filter();
add_item($f->rules->rule, ['enabled' => '1', 'action' => 'pass', 'quick' => '1', 'direction' => 'in', 'ipprotocol' => 'inet',
                           'protocol' => 'any', 'sequence' => $seq, 'interface' => 'enc0', 'source_net' => $src,
                           'destination_net' => $dst, 'description' => $descr]);
persist($f, 'filter-enc0');
Config::getInstance()->save();
echo "SAVED\n";
