<?php
// IPsec "enabled" is a LegacyLinkField (ipsec.enable): the model does not serialize it, so set the legacy node.
require_once '/tmp/opn/lib.php';
use OPNsense\Core\Config;
$c = cfg();
if (!isset($c->ipsec)) $c->addChild('ipsec');
$c->ipsec->enable = '1';
Config::getInstance()->save();
echo "SAVED\n";
