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
        $conn->exec("CREATE TABLE `open_games` (
  `team_id` varchar(11) NOT NULL,
  `team_name` varchar(100) NOT NULL,
  `channel_id` varchar(11) NOT NULL,
  `channel_name` varchar(100) NOT NULL,
  `initiating_user` varchar(100) NOT NULL,
  `initiating_user_name` varchar(100) NOT NULL,
  `other_user` varchar(100) NOT NULL,
  `other_user_name` varchar(100) NOT NULL,
  `current_player` tinyint(4) NOT NULL,
  `board` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;");
    }
    catch(PDOException $e)
    {
        echo"Connection failed: " . $e->getMessage();
    }
}



?>