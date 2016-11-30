
<?php

header('Content-Type: application/json');

$dbopts = parse_url(getenv('CLEARDB_DATABASE_URL'));
$app = new TicTacToeApplication($dbopts["host"], $dbopts["user"], $dbopts["pass"], $dbopts["path"]);
$app->executeRequest($_REQUEST);

//$app = new TicTacToeApplication("localhost", "root", "", "test");
//echo $app->executeRequest(testInit());

function testInit() {
    $request = array();
    $request['token'] = 'D7uhgjErhug6wBAbBi2Ebudn';
    $request['team_id'] = '1';
    $request['team_domain'] = 'ae32731568test0';
    $request['channel_id'] = '2';
    $request['channel_name'] = 'privategroup';
    $request['user_id'] = 'U3195GSCE';
    $request['user_name'] = 'preddy'; //'oxo';
    $request['command'] = '/ttt';
    $request['text'] = "c2"; //'@oxo'; //'c1'; $_REQUEST['position']; //'@slackbot';
    $request['response_url'] = 'https://hooks.slack.com/commands/T2ZTCB1EU/108596885952/xeGk7fDf32RJwSdMZSw2fd8E';
    return $request;
}

class Utilities {
    static function verify($condition, $message) {
        if (!$condition) {
            throw new Exception($message);
        }
    }
}

class TicTacToeApplication {
    private $dbConnection = null;
    private $request = null;

    function __construct($host, $user, $password, $database) {
        try {
            $this->dbConnection = new PDO("mysql:host=$host;dbname=" . ltrim($database,'/'), $user, $password);
            echo "successful!";
        } catch(PDOException $e) {
            echo "not successful!";
        }
    }

    function executeRequest($request) {

        $this->request = $request;
        $newGameMatches = array();
        $movePlayedMatches = array();
        $displayBoardMatches = array();

        $text = $this->request['text'];
        preg_match("/[@]([a-zA-Z0-9]+)/i", $text, $newGameMatches);
        preg_match("/([abc][123])/i", $text, $movePlayedMatches);
        preg_match("/display/i", $text, $displayBoardMatches);

        try {
            if (count($newGameMatches) > 0) {
                $returnText = $this->createTicTacToeGame($newGameMatches[1]);
            } else if (count($movePlayedMatches)) {
                $returnText = $this->playTicTacToeGame($movePlayedMatches[1]);
            } else if (count($displayBoardMatches)) {
                $returnText = $this->displayTicTacToeGame();
            } else {
                $returnText = Utilities::verify(false, "Invalid command.");
            }
            return json_encode([
                "response_type" => "in_channel",
                "text" => $returnText,
            ]);

        } catch (Exception $e) {
            $returnText = "There was a problem with your command: " . $e->getMessage();
            return json_encode([
                "response_type" => "in_channel",
                "text" => $returnText,
            ]);
        }
    }

    function getBoardFromDb($deleteIfCompleted=false) {

        $token = $this->request['token'];
        $teamId = $this->request['team_id'];
        $teamDomain = $this->request['team_domain'];
        $channelId = $this->request['channel_id'];
        $channelName = $this->request['channel_name'];
        $userId = $this->request['user_id'];
        $userName = $this->request['user_name'];
        $command = $this->request['command'];
        $text = $this->request['text'];
        $responseUrl = $this->request['response_url'];

        $query = "select * from open_games where team_id = '$teamId' and channel_id = '$channelId'";
        $results = $this->dbConnection->query($query);
        if ($results->rowCount() == 0) {
            return null;
        } else {
            Utilities::verify($results->rowCount() == 1, "Internal exception... Found more than 1 row for a game.");

            foreach ($results as $row) {
                // print_r($row);
                $game = new TicTacToeGame(
                    $this->dbConnection,
                    $row['team_id'],
                    $row['channel_id'],
                    $row['current_player'],
                    $row['initiating_user'],
                    $row['initiating_user_name'],
                    $row['other_user'],
                    $row['other_user_name'],
                    $row['board']
                );

                if ($deleteIfCompleted && ($row['current_player']  == 2 || $row['current_player'] == 3 || $row['current_player'] == 4)) {
                    $deleteQuery = "delete from open_games where team_id = '$teamId' and channel_id = '$channelId' limit 1";
                    // echo "query = " . $deleteQuery . "\n";
                    // echo "about to delete existing row bc its done\n";
                    $this->dbConnection->query($deleteQuery);
                    return null;
                } else {
                    return $game;
                }
            }
        }
    }

    function createTicTacToeGame($user2) {
        echo "about to create";
        $oldBoard = $this->getBoardFromDb(true);
        $status = $oldBoard ? $oldBoard->getStatus() : "";
        Utilities::verify(is_null($oldBoard), "Game already exists! \n\n" . $status);

        $channelId = $this->request['channel_id'];
        $game = new TicTacToeGame(
            $this->request['team_id'],
            $channelId,
            1,
            $this->request['user_id'],
            $this->request['user_name'],
            1111,
            $user2
        );
        $game->saveToDb();
        return $game->getStatus();
    }

    function displayTicTacToeGame() {

        $board = $this->getBoardFromDb($this->request);
        Utilities::verify(!is_null($board), "There is no ongoing game.");
        return $board->getStatus();
    }

    function playTicTacToeGame($position) {

        $board = $this->getBoardFromDb($this->request);

        Utilities::verify(!is_null($board), "There is no ongoing game.  To start one, use the command /ttt @username.");

        $board->play($position, $this->request['user_name']);
        $board->saveToDb();
        return $board->getStatus();

    }
}

class TicTacToeGame {

    private $teamId;
    private $channelId;
    private $currentPlayer;
    private $board;
    private $user1;
    private $username1;
    private $user2;
    private $username2;

    private $map = ['a1' => 6, 'a2' => 3, 'a3' => 0, 'b1' => 7, 'b2' => 4, 'b3' => 1, 'c1' => 8, 'c2' => 5, 'c3' => 2];
    private $playerToCharacter = [0 => 'O', 1 => 'X'];

    private $playerToName = [];
    private $dbConnection;

    function __construct($dbConnection, $teamId=null, $channelId=null, $currentPlayer=1, $user1=null, $username1=null, $user2=null, $username2=null, $board='         ') {
        $this->teamId = $teamId;
        $this->channelId = $channelId;
        $this->currentPlayer = $currentPlayer;
        $this->user1 = $user1;
        $this->username1 = $username1;
        $this->user2 = $user2;
        $this->username2 = $username2;
        $this->board = $board;

        $this->playerToName[0] = $this->username1;
        $this->playerToName[1] = $this->username2;
        $this->dbConnection = $dbConnection;
    }

    function saveToDb() {

        $query = "insert into
                    open_games (`team_id`, `channel_id`, `initiating_user`, `initiating_user_name`, `other_user`, `other_user_name`, `current_player`, `board`)
                    values ('$this->teamId', '$this->channelId', '$this->user1', '$this->username1', '$this->user2', '$this->username2', '$this->currentPlayer', '$this->board')
                    on duplicate key
                    update current_player='$this->currentPlayer', board='$this->board'";
        // echo "query = " . $query . "\n";
        $this->dbConnection->query($query);
    }

    function play($square, $userName)
    {
        Utilities::verify(
            $userName == $this->playerToName[$this->currentPlayer],
            "<@" . $userName . ">, it's not your turn!  It's <@" . $this->playerToName[$this->currentPlayer] . ">'s turn"
        );
        Utilities::verify($this->currentPlayer == '0' || $this->currentPlayer == '1', "No more moves allowed.  Game's over! \n\n" . $this->getStatus());

//         echo "User " . $this->currentPlayer . " played at position " . $square . "\n";
        $index = $this->map[$square];
//         echo "about to replace index = " . $index . "\n";
        $currentCharacter = substr($this->board, $index, 1);
//         echo "character we're about to replace = " . $currentCharacter . "\n";
        Utilities::verify($currentCharacter == ' ', "Playing in a space that's already taken.  \n\n" . $this->getStatus());
        $this->board = substr_replace($this->board, $this->playerToCharacter[$this->currentPlayer], $index, 1);

        // echo "board = " . $this->board . "\n";

        // check if winning board & update $currentPlayer var
        if ($this->checkWinner($this->playerToCharacter[$this->currentPlayer])) {
            // echo "PLAYER $this->currentPlayer WON!\n";
            $this->currentPlayer += 2;
        } else if ($this->checkDraw()) {
            // echo "DRAW!\n";
            $this->currentPlayer = 4;
        } else {
            if ($this->currentPlayer == 0) {
                $this->currentPlayer = 1;
            } else {
                $this->currentPlayer = 0;
            }
        }
    }

    private function checkWinner($user) {
        return preg_match("/" . $user . "{3}[\\sXO]{6}/", $this->board) ||
        preg_match("/[\\sXO]{3}" . $user . "{3}[\\sXO]{3}/", $this->board) ||
        preg_match("/[\\sXO]{6}" . $user . "{3}/", $this->board) ||
        preg_match("/" . $user . "{1}[\\sXO]{2}" . $user . "{1}[\\sXO]{2}" . $user . "{1}[\\sXO]{2}/", $this->board) ||
        preg_match("/[\\sXO]{1}" . $user . "{1}[\\sXO]{2}" . $user . "{1}[\\sXO]{2}" . $user . "{1}[\\sXO]{1}/", $this->board) ||
        preg_match("/[\\sXO]{2}" . $user . "{1}[\\sXO]{2}" . $user . "{1}[\\sXO]{2}" . $user . "/", $this->board) ||
        preg_match("/" . $user . "{1}[\\sXO]{3}" . $user . "{1}[\\sXO]{3}" . $user . "{1}/", $this->board) ||
        preg_match("/[\\sXO]{2}" . $user . "{1}[\\sXO]{1}" . $user . "{1}[\\sXO]{1}" . $user . "{1}[\\sXO]{2}/", $this->board);
    }

    private function checkDraw() {
        return preg_match("/[XO]{9}/", $this->board);
    }

    function getStatus() {

        $status = "```Current Board\n\n" .
            $this->printRow('3', substr($this->board, 0, 3), true) .
            $this->printRow('2', substr($this->board, 3, 3), true) .
            $this->printRow('1', substr($this->board, 6, 3), true) .
            "     a   b   c ``` ";
        if ($this->currentPlayer < 2) {
            $status .= "It's <@" . $this->playerToName[$this->currentPlayer] . ">'s turn!";
        } else if ($this->currentPlayer == 2 || $this->currentPlayer == 3 || $this->currentPlayer == 4) {
            if ($this->currentPlayer < 4) {
                $status .= "Game's over! <@" . $this->playerToName[$this->currentPlayer-2] . "> is the winner!";
            } else {
                $status .= "Game's over! It's a draw!";
            }
            $status .= "\n\nTo start a new game, issue the command /ttt @username";
        }

        return $status;
    }

    private function printRow($rowLetter, $string, $withLine=false) {
        $str = "$rowLetter  |";
        for($i = 0; $i < 3; $i++) {
            $str .= " " . substr($string, $i, 1) . " |";
        }
        if ($withLine) {
            $str .= "\n   |---+---+---|\n";
        }
        return $str;
    }
}





?>