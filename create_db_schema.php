<?php

connectToDb();
function connectToDb() {
    $dbopts = parse_url(getenv('DATABASE_URL'));
    print_r($dbopts);

    $servername = $dbopts["host"];
    $username = $dbopts["user"];
    $password = $dbopts["pass"];
    $port = $dbopts["port"];
    $dbName = $dbopts["path"];

    try {
        $conn = new PDO("pgsql:host=$servername;dbname=" . ltrim($dbopts["path"],'/') . ";port=$port", $username, $password);
        // set the PDO error mode to exception
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        echo"Connected successfully";
    }
    catch(PDOException $e)
    {
        echo"Connection failed: " . $e->getMessage();
    }
}



?>