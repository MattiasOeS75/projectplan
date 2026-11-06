<?php
// Kopiera till config.php och fyll i. config.php ligger i .gitignore och blockeras av .htaccess.
return [
  'PGHOST'        => 'db.r103.websupport.se',
  'PGPORT'        => 5432,
  'PGDATABASE'    => '',
  'PGUSER'        => '',
  'PGPASSWORD'    => '',
  'PGSSL'         => false,   // Websupport: databasen ligger på samma server utan SSL
  'MONDAY_TOKEN'  => '',
  'MONDAY_BOARDS' => '1071363420',
  'MONDAY_FRILANS' => '1083892887',   // Frilansare-board (grupp Aktiva Frilansare)
  'MONDAY_FRANVARO' => '1071497633',  // Frånvaro-board (perioder i underobjekt)
];
