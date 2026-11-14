<?php
// U3 test helper (L2): set the hour range of schedule HORARIO_DESPACHO, e.g. 00:00-23:59 to test the allowed side
// now, then 10:00-23:00 to restore it (UTC; = 07:00-20:00 Chile). Optional arg 2: weekdays (1 = Monday ... 7 = Sunday).
// Run on FW-ZONA-CENTRO: php /tmp/opn/u3-horario-prueba.php 00:00-23:59, then: configctl filter reload
require_once '/tmp/opn/lib.php';
use OPNsense\Core\Config;
foreach (cfg()->schedules->children() as $s) {
    if ((string)$s->name == 'HORARIO_DESPACHO') { $s->timerange->hour = $argv[1]; $s->timerange->position = $argv[2] ?? '1,2,3,4,5,6'; }
}
Config::getInstance()->save();
echo "HORA {$argv[1]}\n";
