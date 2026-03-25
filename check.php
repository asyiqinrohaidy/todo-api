<?php
foreach (glob('config/*.php') as $f) {
    echo $f . PHP_EOL;
    include $f;
    echo " OK" . PHP_EOL;
}