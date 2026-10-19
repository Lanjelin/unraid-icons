<?php
// Process-boundary fixture: return libvirt/Docker CLI records, rejecting unknown commands.
declare(strict_types=1);
$fixture = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$key = implode(' ', array_slice($argv, 2));
if (!array_key_exists($key, $fixture)) { fwrite(STDERR, "Unexpected command: $key\n"); exit(2); }
fwrite(STDOUT, $fixture[$key]);
