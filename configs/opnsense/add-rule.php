<?php
// Add one pass rule: php add-rule.php <interface> <protocol> <source> <destination> <dest-port|-> <sequence> <description>
require_once '/tmp/opn/lib.php';
use OPNsense\Core\Config;
[$if, $proto, $src, $dst, $port, $seq, $descr] = array_slice($argv, 1, 7);
$f = new OPNsense\Firewall\Filter();
$r = ['enabled' => '1', 'action' => 'pass', 'quick' => '1', 'direction' => 'in', 'ipprotocol' => 'inet', 'protocol' => $proto,
      'sequence' => $seq, 'interface' => $if, 'source_net' => $src, 'destination_net' => $dst, 'description' => $descr];
if ($port !== '-') $r['destination_port'] = $port;
add_item($f->rules->rule, $r);
persist($f, "rule $descr");
Config::getInstance()->save();
