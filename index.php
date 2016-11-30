
<?php

header('Content-Type: application/json');

$dbopts = parse_url(getenv('CLEARDB_DATABASE_URL'));
$app = new TicTacToeApplication($dbopts["host"], $dbopts["user"], $dbopts["pass"], $dbopts["path"]);
echo $app->executeRequest($_REQUEST);
//
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
    $request['text'] = '@oxo'; //"c2"; //'@oxo'; //'c1'; $_REQUEST['position']; //'@slackbot';
    $request['response_url'] = 'https://hooks.slack.com/commands/T2ZTCB1EU/108596885952/xeGk7fDf32RJwSdMZSw2fd8E';
    return $request;
}

class Utilities {
    static function verify($condition, $message) {
        if (!$condition) {
            throw new Exception($message);
        }
    }

    static function validateRequest($request) {
        static::verify($request["token"] == "D7uhgjErhug6wBAbBi2Ebudn", "Requests must come from Slack");
    }

}

class TicTacToeApplication {
    private $dbConnection = null;
    private $request = null;

    function __construct($host, $user, $password, $database) {
        try {
            $this->dbConnection = new PDO("mysql:host=$host;dbname=" . ltrim($database,'/'), $user, $password);
        } catch(PDOException $e) {

        }
    }

    function executeRequest($request) {
        $this->request = $request;
        try {
            Utilities::validateRequest($this->request);
            $text = $request['text'];

            $newGameMatches = array();
            $movePlayedMatches = array();
            $displayBoardMatches = array();

            preg_match("/[@]([a-zA-Z0-9]+)/i", $text, $newGameMatches);
            preg_match("/([abc][123])/i", $text, $movePlayedMatches);
            preg_match("/display/i", $text, $displayBoardMatches);

            $game = null;
            if (count($newGameMatches) > 0) {
                $game = $this->createTicTacToeGame($newGameMatches[1]);
            } else if (count($movePlayedMatches)) {
                $game = $this->playTicTacToeGame($movePlayedMatches[1]);
            } else if (count($displayBoardMatches)) {
                $game = $this->displayTicTacToeGame();
            }
            Utilities::verify(!is_null($game), $text . " is not a valid command.");

            return json_encode([
                "response_type" => "in_channel",
                "text" => $game->getStatus()
            ]);

        } catch (Exception $e) {
            return json_encode([
                "text" => "There was a problem with your command: " . $e->getMessage()
            ]);
        }
    }

    private function getBoardFromDb($deleteIfCompleted=false) {

        $teamId = $this->request['team_id'];
        $channelId = $this->request['channel_id'];

        $query = "select * from open_games where team_id = '$teamId' and channel_id = '$channelId'";
        $results = $this->dbConnection->query($query);
        if ($results->rowCount() == 0) {
            return null;
        } else {
            Utilities::verify($results->rowCount() == 1, "Internal Exception.  Found more than 1 row for a game.");

            foreach ($results as $row) {
                $game = new TicTacToeGame(
                    $this->dbConnection,
                    $row['team_id'],
                    $row['channel_id'],
                    $row['current_player'],
                    $row['initiating_user_name'],
                    $row['other_user_name'],
                    $row['board']
                );

                if ($deleteIfCompleted && ($row['current_player']  == 2 || $row['current_player'] == 3 || $row['current_player'] == 4)) {
                    $deleteQuery = "delete from open_games where team_id = '$teamId' and channel_id = '$channelId' limit 1";
                    $this->dbConnection->query($deleteQuery);
                    return null;
                } else {
                    return $game;
                }
            }
        }
    }

    private function createTicTacToeGame($username2) {
        $oldBoard = $this->getBoardFromDb(true);
        $status = $oldBoard ? $oldBoard->getStatus() : "";
        Utilities::verify(is_null($oldBoard), "Game already exists! \n\n" . $status);

        $game = new TicTacToeGame(
            $this->dbConnection,
            $this->request['team_id'],
            $this->request['channel_id'],
            1,
            $this->request['user_name'],
            $username2
        );
        $game->saveToDb();
        return $game;
    }

    private function displayTicTacToeGame() {
        $board = $this->getBoardFromDb($this->request);
        Utilities::verify(!is_null($board), "There is no ongoing game.");
        return $board;
    }

    private function playTicTacToeGame($position) {
        $board = $this->getBoardFromDb($this->request);
        Utilities::verify(!is_null($board), "There is no ongoing game.  To start one, use the command /ttt @username.");
        $board->play($position, $this->request['user_name']);
        $board->saveToDb();
        return $board;
    }
}

class TicTacToeGame {

    private $teamId;
    private $channelId;
    private $currentPlayer;
    private $board;
    private $username1;
    private $username2;

    private $map = ['a1' => 6, 'a2' => 3, 'a3' => 0, 'b1' => 7, 'b2' => 4, 'b3' => 1, 'c1' => 8, 'c2' => 5, 'c3' => 2];
    private $playerToCharacter = [0 => 'O', 1 => 'X'];

    private $playerToName = [];
    private $dbConnection;

    const PLAYER1_TURN = 0;
    const PLAYER2_TURN = 1;
    const PLAYER1_WON = 2;
    const PLAYER2_WON = 3;
    const DRAW = 4;

    function __construct($dbConnection, $teamId=null, $channelId=null, $currentPlayer=1, $username1=null, $username2=null, $board='         ') {
        $this->dbConnection = $dbConnection;
        $this->teamId = $teamId;
        $this->channelId = $channelId;
        $this->currentPlayer = $currentPlayer;
        $this->username1 = $username1;
        $this->username2 = $username2;
        $this->board = $board;

        $this->playerToName[0] = $this->username1;
        $this->playerToName[1] = $this->username2;
    }

    function saveToDb() {

        $query = "insert into
                    open_games (
                      `team_id`, 
                      `channel_id`, 
                      `initiating_user_name`, 
                      `other_user_name`, 
                      `current_player`, 
                      `board`
                    )
                    values (
                      '$this->teamId', 
                      '$this->channelId', 
                      '$this->username1', 
                      '$this->username2', 
                      '$this->currentPlayer', 
                      '$this->board'
                    )
                    on duplicate key
                    update current_player='$this->currentPlayer', board='$this->board'";
        $this->dbConnection->query($query);
    }

    function play($square, $userName) {

        Utilities::verify(
            $userName == $this->playerToName[$this->currentPlayer],
            "It's not your turn!  It's <@" . $this->playerToName[$this->currentPlayer] . ">'s turn"
        );
        Utilities::verify($this->currentPlayer == '0' || $this->currentPlayer == '1', "No more moves allowed.  Game's over! \n\n" . $this->getStatus());

        $index = $this->map[$square];
        $currentCharacter = substr($this->board, $index, 1);
        Utilities::verify($currentCharacter == ' ', "Playing in a space that's already taken.  \n\n" . $this->getStatus());
        $this->board = substr_replace($this->board, $this->playerToCharacter[$this->currentPlayer], $index, 1);

        if ($this->checkWinner($this->playerToCharacter[$this->currentPlayer])) {
            $this->currentPlayer += 2;
        } else if ($this->checkDraw()) {
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
        $topRow = "/" . $user . "{3}[\\sXO]{6}/";
        $middleRow = "/[\\sXO]{3}" . $user . "{3}[\\sXO]{3}/";
        $bottomRow = "/[\\sXO]{6}" . $user . "{3}/";
        $leftColumn = "/" . $user . "{1}[\\sXO]{2}" . $user . "{1}[\\sXO]{2}" . $user . "{1}[\\sXO]{2}/";
        $middleColumn = "/[\\sXO]{1}" . $user . "{1}[\\sXO]{2}" . $user . "{1}[\\sXO]{2}" . $user . "{1}[\\sXO]{1}/";
        $rightColumn = "/[\\sXO]{2}" . $user . "{1}[\\sXO]{2}" . $user . "{1}[\\sXO]{2}" . $user . "/";
        $diagonal1 = "/" . $user . "{1}[\\sXO]{3}" . $user . "{1}[\\sXO]{3}" . $user . "{1}/";
        $diagonal2 = "/[\\sXO]{2}" . $user . "{1}[\\sXO]{1}" . $user . "{1}[\\sXO]{1}" . $user . "{1}[\\sXO]{2}/";

        return preg_match($topRow, $this->board) ||
            preg_match($middleRow, $this->board) ||
            preg_match($bottomRow, $this->board) ||
            preg_match($leftColumn, $this->board) ||
            preg_match($middleColumn, $this->board) ||
            preg_match($rightColumn, $this->board) ||
            preg_match($diagonal1, $this->board) ||
            preg_match($diagonal2, $this->board);
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

        if ($this->currentPlayer == static::PLAYER1_TURN || $this->currentPlayer == static::PLAYER2_TURN) {
            $status .= "It's <@" . $this->playerToName[$this->currentPlayer] . ">'s turn!";
        } else if ($this->currentPlayer == static::PLAYER1_WON ||
            $this->currentPlayer == static::PLAYER2_WON ||
            $this->currentPlayer == static::DRAW) {
            if ($this->currentPlayer == static::DRAW) {
                $status .= "Game's over! It's a draw!";
            } else {
                $status .= "Game's over! <@" . $this->playerToName[$this->currentPlayer-2] . "> is the winner!";
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