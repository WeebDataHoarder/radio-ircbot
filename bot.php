<?php

require_once __DIR__ . "/vendor/autoload.php";

require_once "config.php";
require_once "deps/TorrentIndex.php";
require_once "deps/Database.php";
require_once "deps/IRCClient.php";

setlocale(LC_CTYPE, "en_US.UTF-8");

const PERMISSION_SUPERADMIN = 5;
const PERMISSION_ADMIN = 4;
const PERMISSION_MOD = 3;
const PERMISSION_PUSER = 2;
const PERMISSION_USER = 1;
const PERMISSION_NONE = 0;
const DEFAULT_PERMISSION = PERMISSION_NONE;

const FORMAT_COLOR_GREEN = "\x0303";
const FORMAT_COLOR_RED = "\x0304";
const FORMAT_COLOR_ORANGE = "\x0307";
const FORMAT_COLOR_YELLOW = "\x0308";
const FORMAT_COLOR_LIGHT_GREEN = "\x0309";
const FORMAT_BOLD = "\x02";
const FORMAT_ITALIC = "\x1D";
const FORMAT_UNDERLINE = "\x1F";
const FORMAT_RESET = "\x0F";

const AUTH_ERROR_MESSAGE = !BOT_USE_SATSUKI ? "You are not identified, please identify via Services and ".BOT_NICK.", .associate if needed" : "You are not identified, please identify via Satsuki / ".BOT_NICK." and .associate if needed";

$dessLimiter = [

];

function sendIRCMessage($message, $to, $notice = false){
    global $socket;
    $cmd = $notice ? "NOTICE" : "PRIVMSG";
    if($to === BOT_RADIO_CHANNEL){
        //$cmd = "CPRIVMSG";
    }
    $message = str_replace(["\r", "\n", "\\r", "\\n"], "", $message);
    echo "[RAWOUT] $cmd $to :$message\n";
    fwrite($socket, "$cmd $to :$message\r\n");
    fflush($socket);
}



function removePing($s){
    $groupMatch = " \t:\\-_,\"'=\\)\\(\\.\\/#@<>";
    return preg_replace("/([^$groupMatch]{1})([^$groupMatch]+)([$groupMatch]|$)/iu", "$1\u{FEFF}$2$3", $s);
}

function formatTrackNameShort($data, $started = null){

    $extToType = [
        "flac" => "FLAC",
        "ogg" => "OGG",
        "opus" => "Opus",
        "tta" => "TTA",
        "mp3" => "MP3",
        "m4a" => "AAC",
        "aac" => "AAC"
    ];
    $format = null;
    if(preg_match("/\\.([^ .]+)$/iu", $data["path"], $matches) > 0){
        $format = isset($extToType[strtolower($matches[1])]) ? $extToType[strtolower($matches[1])] : null;
    }

    if($started !== null){
        $timeElapsed = min($data["duration"], round(microtime(true) - $started));
        $time = " [" . ($format !== null ? $format . " " : "") . floor($timeElapsed / 60) . ":" . str_pad($timeElapsed % 60, 2, "0", STR_PAD_LEFT) . "/" . floor($data["duration"] / 60) . ":" . str_pad($data["duration"] % 60, 2, "0", STR_PAD_LEFT) . "]";
    }else{
        $time = " [" . ($format !== null ? $format . " " : "") . floor($data["duration"] / 60) . ":" . str_pad($data["duration"] % 60, 2, "0", STR_PAD_LEFT) . "]";
    }
    $title = FORMAT_UNDERLINE . substr($data["hash"], 0, 12) . FORMAT_RESET . " :: " . FORMAT_BOLD . cleanupCodes($data["title"]) . FORMAT_RESET . " by " . FORMAT_BOLD . cleanupCodes($data["artist"]) . FORMAT_RESET . " from " .  FORMAT_BOLD . cleanupCodes($data["album"]) . FORMAT_RESET . $time;
    foreach($data["favored_by"] as $k => $v){
        $data["favored_by"][$k] = removePing($v);
    }

    if(count($data["favored_by"]) > 3){
        $title .= FORMAT_COLOR_RED . FORMAT_ITALIC . " ❤︎ ". count($data["favored_by"]) ." users" . FORMAT_RESET;
    }else if(count($data["favored_by"]) > 0){
        $title .= FORMAT_COLOR_RED . FORMAT_ITALIC . " ❤︎ ". implode(", ", $data["favored_by"]) . FORMAT_RESET;
    }
    return $title;
}

function formatTrackName($data, $started = null){
    global $torrentIndex;

    $extToType = [
        "flac" => "FLAC",
        "ogg" => "OGG",
        "opus" => "Opus",
        "tta" => "TTA",
        "mp3" => "MP3",
        "m4a" => "AAC",
        "aac" => "AAC"
    ];
    $format = null;
    if(preg_match("/\\.([^ .]+)$/iu", $data["path"], $matches) > 0){
        $format = isset($extToType[strtolower($matches[1])]) ? $extToType[strtolower($matches[1])] : null;
    }

    if($started !== null){
        $timeElapsed = min($data["duration"], round(microtime(true) - $started));
        $time = " [" . ($format !== null ? $format . " " : "") . floor($timeElapsed / 60) . ":" . str_pad($timeElapsed % 60, 2, "0", STR_PAD_LEFT) . "/" . floor($data["duration"] / 60) . ":" . str_pad($data["duration"] % 60, 2, "0", STR_PAD_LEFT) . "]";
    }else{
        $time = " [" . ($format !== null ? $format . " " : "") . floor($data["duration"] / 60) . ":" . str_pad($data["duration"] % 60, 2, "0", STR_PAD_LEFT) . "]";
    }
    $title = FORMAT_UNDERLINE . substr($data["hash"], 0, 12) . FORMAT_RESET . " :: " . FORMAT_BOLD . cleanupCodes($data["title"]) . FORMAT_RESET . " by " . FORMAT_BOLD . cleanupCodes($data["artist"]) . FORMAT_RESET . " from " .  FORMAT_BOLD . cleanupCodes($data["album"]) . FORMAT_RESET . $time;
    if(isset($data["play_count"])){
        $title .= " :: " . $data["play_count"] ." play(s) " . FORMAT_RESET;
    }
    if(isset($data["source"]) and strlen($data["source"]) > 0 and $data["source"][0] === "@"){
        $title .= " :: by " . FORMAT_COLOR_YELLOW . removePing($data["source"]) ." " . FORMAT_RESET;
    }
    foreach($data["favored_by"] as $k => $v){
        $data["favored_by"][$k] = removePing($v);
    }

    $extra = "";
    $tags = [];
    $groupId = null;
    $torrentId = null;
    $siteType = null;
    $catalog = null;
    $series = [];
    $group = null;

    foreach($data["tags"] as $tag){
        if(preg_match("/^(ab|jps|red|bbt)t\\-([0-9]+)$/iu", $tag, $matches) > 0){
            $torrentId = $matches[2];
            $siteType = $matches[1];
        }else if(preg_match("/^(ab|jps|red|bbt)g\\-([0-9]+)$/iu", $tag, $matches) > 0){
            $groupId = $matches[2];
            $siteType = $matches[1];
        }else if(preg_match("/^(ab|jps|red|bbt)s\\-([0-9]+)$/iu", $tag, $matches) > 0){
            $series[] = $matches[1] . "-" . $matches[2];
        }else if(preg_match("/^catalog\\-(.+)$/iu", $tag, $matches) > 0){
            $catalog = strtoupper($matches[1]);
        }else if(preg_match("/^playlist\\-([a-z0-9\\-_]+)$/", $tag, $matches) > 0){

        }else if(!(preg_match("/^(ab|jps|red|bbt)[tgsa]\\-([0-9]+)$/iu", $tag, $matches) > 0) and !(preg_match("/^playlist\\-([a-z0-9\\-_]+)$/", $tag, $matches)) > 0){
            $tags[] = $tag;
        }
    }



    if($torrentId !== null and $groupId !== null){
        if($siteType === "ab"){
            $group = $torrentIndex->getGroupBySiteId($groupId, 1);
            //$extra .= " :: " . "https://animebytes.tv/torrent/".$matches[1]."/group";
            $extra .= " :: " . "https://animebytes.tv/torrents2.php?id=". $groupId . "&torrentid=" . $torrentId;
            $torrent = $torrentIndex->getTorrentBySiteId($torrentId, 1);
            if(isset($torrent["metadata"]["edition"]["catalog"]) and $torrent["metadata"]["edition"]["catalog"] !== null){
                $catalog = strtoupper($torrent["metadata"]["edition"]["catalog"]);
            }
        }else if($siteType === "jps"){
            $group = $torrentIndex->getGroupBySiteId($groupId, 2);
            $extra .= " :: " . "https://jpopsuki.eu/torrents.php?id=". $groupId . "&torrentid=" . $torrentId;
            $torrent = $torrentIndex->getTorrentBySiteId($torrentId, 2);
            if(isset($torrent["metadata"]["edition"]["catalog"]) and $torrent["metadata"]["edition"]["catalog"] !== null){
                $catalog = strtoupper($torrent["metadata"]["edition"]["catalog"]);
            }
        }else if($siteType === "red"){
            $extra .= " :: " . "https://redacted.ch/torrents.php?id=". $groupId . "&torrentid=" . $torrentId;
        }else if($siteType === "bbt"){
            $extra .= " :: " . "https://bakabt.me/torrent/" . $torrentId . "/show";
        }
    }

    if($catalog !== null){
        $extra = " :: ". FORMAT_BOLD . $catalog . FORMAT_RESET . $extra;
    }

    if(isset($data["lyrics"]) and ((isset($data["lyrics"]["ass"]) or isset($data["lyrics"]["timed"])) or in_array("ass", $data["lyrics"], true) or in_array("timed", $data["lyrics"], true))){
        $extra = " :: 🎤" . $extra;
    }


    if($group !== null and isset($group["metadata"]["featured"])){
        $series = $group["metadata"]["featured"];
    }

    if(count($series) > 0){
        $extra .= " :: " . implode(", ", $series);
    }

    if(count($tags) > 0){
        $title .= FORMAT_ITALIC . " # ". implode(", ", $tags) . FORMAT_RESET;
    }

    if(count($data["favored_by"]) > 10){
        $title .= FORMAT_COLOR_RED . FORMAT_ITALIC . " ❤︎ ". count($data["favored_by"]) ." users" . FORMAT_RESET;
    }else if(count($data["favored_by"]) > 0){
        $title .= FORMAT_COLOR_RED . FORMAT_ITALIC . " ❤︎ ". implode(", ", $data["favored_by"]) . FORMAT_RESET;
    }
    return $title . $extra;
}

function cleanupCodes($text){
    return preg_replace('/[\r\n\t]|[\x02\x0F\x16\x1D\x1F]|\x03(\d{,2}(,\d{,2})?|(\x9B|\x1B\[)[0-?]*[ -\/]*[@-~])?/u', "",  $text);
}

function secondsToTime($seconds) {
    return floor($seconds / (3600 * 24)) . " day(s)";
}




function handleNewJoin($sender, $senderCloak, $channel){
    global $torrentIndex, $lastResult, $db;
}

function handleNewCTCP($sender, $senderCloak, $to, $message){
    if($to === BOT_NICK or $to === TEMP_NICK){
        $answer = $sender;
    }else{
        $answer = $to;
    }

    switch ($message){
        case "VERSION":
            sendIRCMessage("\x01" . BOT_NICK." version " . substr(trim(file_get_contents(".version")), 0, 8) . "\x01", $answer, true);
            break;
    }
}
function handleNewMessage($sender, $senderCloak, $to, $message, $isAction = false){
    global $torrentIndex, $lastResult, $db;
    $message = cleanupCodes(str_replace(["“", "”", '１','２','３','４','５','６','７','８','９','０'], ["\"", "\"", '1','2','3','4','5','6','7','8','9','0'], preg_replace("/(\u{200B}|\u{FEFF})/u", "", trim($message))));

    global $extraAuth;

    if(isset($extraAuth[trim($senderCloak)])){
        $senderCloak = $extraAuth[trim($senderCloak)];
    }

    $authSender = null;
    if(preg_match("/^([0-9]+)@([^. ]+)\\.([^. ]+)\\.AnimeBytes$/u", trim($senderCloak), $matches) > 0){
        $authSender = [
            "id" => (int) $matches[1],
            "nick" => $matches[2],
            "class" => strtolower($matches[3]),
        ];
    }

    $originalSender = ["id" => $authSender !== null ? $authSender["id"] . "!" . $authSender["nick"] : strtolower($sender), "user" => $sender, "mask" => $senderCloak, "auth" => $authSender, "identified" => false, "record" => null];


    $originalSender["name"] = $authSender !== null ? strtolower($authSender["nick"]) : strtolower($sender);

    $currentPermissions = DEFAULT_PERMISSION;


    $groupPermissions = [
        "aka-chan" => PERMISSION_USER,
        "user" => PERMISSION_USER,

        "poweruser" => PERMISSION_PUSER,

        "elite" => PERMISSION_PUSER,
        "torrentmaster" => PERMISSION_PUSER,
        "legend" => PERMISSION_PUSER,
        "sensei" => PERMISSION_PUSER,

        "vip" => PERMISSION_PUSER,
        "communityceleb" => PERMISSION_PUSER,
        "editor" => PERMISSION_PUSER,
        "appreviewer" => PERMISSION_PUSER,
        "forumstaff" => PERMISSION_PUSER,
        "torrentsupport" => PERMISSION_PUSER,
        "staff" => PERMISSION_PUSER,
    ];

    $radioUser = $db->getUserByIdentifier($originalSender["id"]);
    if($radioUser !== null){
        $currentPermissions = (isset($radioUser["user_metadata"]["permission"]) and constant($radioUser["user_metadata"]["permission"]) !== null) ? constant($radioUser["user_metadata"]["permission"]) : PERMISSION_USER;
        $originalSender["identified"] = true;
        $originalSender["apiKey"] = $db->getUserApiKey($originalSender["name"], BOT_KEYID);
        if($originalSender["apiKey"] === null){
            $originalSender["apiKey"] = $db->generateUserApiKey($originalSender["name"], BOT_KEYID);
        }
        $originalSender["name"] = $radioUser["name"];
        $originalSender["record"] = $radioUser;
    }

    if(isset($authSender["class"]) and isset($groupPermissions[$authSender["class"]]) and $groupPermissions[$authSender["class"]] > $currentPermissions){
        $currentPermissions = $groupPermissions[$authSender["class"]];
    }

    $originalSender["permission"] = $currentPermissions;

    $to = strtolower($to);

    $commands = [
        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_ADMIN,
            "match" => "#^\\.quit$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $socket;
                fwrite($socket, "QUIT :Boss' orders (".$originalSender["id"].")\r\n");
                fclose($socket);
                exit();
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_NONE,
            "match" => "#(http|https):\\/\\/animebytes\\.tv\\/torrent\\/([0-9]+)\\/download\\/(.*)#iu",
            "command" => function($originalSender, $answer, $to, $matches) {

                sendIRCMessage($originalSender["user"] . ": You might have just posted your passkey! Please reset it ASAP on https://animebytes.tv/user.php?action=edit#account", $answer);
            }
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_ADMIN,
            "match" => "#^\\.(scoalesce)[ \t]+(.+?)(| !force)$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;

                $results = sendApiMessage("/api/search?limit=5000&orderBy=score&orderDirection=desc&q=" . urlencode($matches[2]), "GET", null, ["Authorization: " . DEFAULT_API_KEY]);
                $totalCoalesced = 0;
                foreach($results as $t){
                    if($totalCoalesced > 20 and trim($matches[3]) !== "!force"){
                        break;
                    }
                    $t = $db->getTrackById($t["id"]);
                    if(count($t["favored_by"]) === 0 and trim($matches[3]) !== "!force"){
                        sendIRCMessage("Too many results, stopping", $answer);
                        continue;
                    }

                    $albumName = addslashes($t["album"]);
                    $artistName = addslashes($t["artist"]);
                    $title = addslashes($t["title"]);
                    $durationStart = $t["duration"] - 3;
                    $durationEnd = $t["duration"] + 3;
                    $hash = addslashes($t["hash"]);
                    $audioHash = "";
                    if(isset($t["audio_hash"]) and $t["audio_hash"] !== null){
                        $audioHash = " OR audio='" . $t["audio_hash"] ."'";
                    }
                    $query = "(NOT hash='$hash' AND ((title='$title' AND duration>$durationStart AND duration<$durationEnd AND ((album:'$albumName') OR (artist:'$artistName') OR (duration=".$t["duration"].")) AND (favcount>0 OR playcount>0))$audioHash))";
                    echo "searching for $query\n";
                    $result = sendApiMessage("/api/search?limit=5000&q=" . urlencode($query), "GET", null, ["Authorization: " . DEFAULT_API_KEY]);
                    $coalesced = 0;
                    foreach($result as $song){
                        foreach($song["favored_by"] as $user){
                            $apiKey = $db->getUserApiKey($user, BOT_KEYID);
                            if($apiKey === null){
                                $apiKey = DEFAULT_API_KEY;
                            }
                            if(!in_array($user, $t["favored_by"], true)){
                                sendApiMessage("/api/favorites/".$user."/" . $t["hash"], "PUT", null, ["Authorization: " . $apiKey]);
                                $t["favored_by"][] = $user;
                            }
                            sendApiMessage("/api/favorites/".$user."/" . $song["hash"], "DELETE", null, ["Authorization: " . $apiKey]);
                            ++$coalesced;
                            ++$totalCoalesced;
                        }
                        if($song["play_count"] > 0){
                            pg_query_params($db->dbconn, 'UPDATE history SET song = $1 WHERE song = $2;', [$t["id"], $song["id"]]);
                        }
                    }
                    if($coalesced > 0){
                        $t = $db->getTrackById($t["id"]);
                        sendIRCMessage("Coalesced $coalesced favorites/plays into " . formatTrackName($t), $answer);
                    }
                }
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_ADMIN,
            "match" => "#^\\.(ass)[ \t]+([0-9a-f]{8,32})[ \t]+([^ \t]+)[ \t]*$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;
                $t = $db->getTrackByHash($matches[2]);
                if($t === null){
                    sendIRCMessage("Could not find track", $answer);
                    return;
                }
                $url = trim($matches[3]);
                $content = file_get_contents($url);
                if(strlen($content) > 100){
                    $db->setTrackLyrics($t["id"], "ass", $content);
                    sendIRCMessage("Added ASS lyrics to " . formatTrackName($t), $answer);
                }
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_ADMIN,
            "match" => "#^\\.(lrc)[ \t]+([0-9a-f]{8,32})[ \t]+([^ \t]+)[ \t]*(.*)$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;
                $t = $db->getTrackByHash($matches[2]);
                if($t === null){
                    sendIRCMessage("Could not find track", $answer);
                    return;
                }
                $offset = trim($matches[4]);
                $url = trim($matches[3]);
                $content = file_get_contents($url);
                if(strlen($content) > 100){
                    if(trim($offset) !== ""){
                        $content = str_replace("[offset:0]\n", "", $content);
                        $content = "[offset:".trim($offset)."]\n" . $content;
                    }
                    $db->setTrackLyrics($t["id"], "timed", $content);
                    sendIRCMessage("Added timed lyrics to " . formatTrackName($t), $answer);
                }
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_ADMIN,
            "match" => "#^\\.(coalesce)[ \t]+([0-9a-f]{8,32})[ \t]+([0-9a-f]{8,32})[ \t]*$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;
                $t = $db->getTrackByHash($matches[2]);
                if($t === null){
                    sendIRCMessage("Could not find track", $answer);
                    return;
                }
                $coalesced = 0;
                $result = [
                    $db->getTrackByHash($matches[3])
                ];
                foreach($result as $song){
                    foreach($song["favored_by"] as $user){
                        $apiKey = $db->getUserApiKey($user, BOT_KEYID);
                        if($apiKey === null){
                            $apiKey = DEFAULT_API_KEY;
                        }
                        if(!in_array($user, $t["favored_by"], true)){
                            sendApiMessage("/api/favorites/".$user."/" . $t["hash"], "PUT", null, ["Authorization: " . $apiKey]);
                            $t["favored_by"][] = $user;
                        }
                        sendApiMessage("/api/favorites/".$user."/" . $song["hash"], "DELETE", null, ["Authorization: " . $apiKey]);
                        ++$coalesced;
                    }
                    if($song["play_count"] > 0){
                        pg_query_params($db->dbconn, 'UPDATE history SET song = $1 WHERE song = $2;', [$t["id"], $song["id"]]);
                    }
                }
                $t = $db->getTrackById($t["id"]);
                sendIRCMessage("Coalesced $coalesced favorites/plays into " . formatTrackName($t), $answer);
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_MOD,
            "match" => "#^\\.(coalesce)[ \t]+([0-9a-f]{8,32})[ \t]*$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;
                $t = $db->getTrackByHash($matches[2]);
                if($t === null){
                    sendIRCMessage("Could not find track", $answer);
                    return;
                }
                $albumName = addslashes($t["album"]);
                $artistName = addslashes($t["artist"]);
                $title = addslashes($t["title"]);
                $durationStart = $t["duration"] - 3;
                $durationEnd = $t["duration"] + 3;
                $hash = addslashes($t["hash"]);
                $audioHash = "";
                if(isset($t["audio_hash"]) and $t["audio_hash"] !== null){
                    $audioHash = " OR audio='" . $t["audio_hash"] ."'";
                }
                $query = "(NOT hash='$hash' AND ((title='$title' AND duration>$durationStart AND duration<$durationEnd AND ((album:'$albumName') OR (artist:'$artistName') OR (duration=".$t["duration"].")) AND (favcount>0 OR playcount>0))$audioHash))";
                $result = sendApiMessage("/api/search?limit=5000&q=" . urlencode($query), "GET", null, ["Authorization: " . DEFAULT_API_KEY]);
                $coalesced = 0;
                foreach($result as $song){
                    foreach($song["favored_by"] as $user){
                        $apiKey = $db->getUserApiKey($user, BOT_KEYID);
                        if($apiKey === null){
                            $apiKey = DEFAULT_API_KEY;
                        }
                        if(!in_array($user, $t["favored_by"], true)){
                            sendApiMessage("/api/favorites/".$user."/" . $t["hash"], "PUT", null, ["Authorization: " . $apiKey]);
                            $t["favored_by"][] = $user;
                        }
                        sendApiMessage("/api/favorites/".$user."/" . $song["hash"], "DELETE", null, ["Authorization: " . $apiKey]);
                        ++$coalesced;
                    }
                    if($song["play_count"] > 0){
                        pg_query_params($db->dbconn, 'UPDATE history SET song = $1 WHERE song = $2;', [$t["id"], $song["id"]]);
                    }
                }
                $t = $db->getTrackById($t["id"]);
                sendIRCMessage("Coalesced $coalesced favorites/plays into " . formatTrackName($t), $answer);
            },
        ],

        [
            "targets" => [BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_MOD,
            "match" => "#^\\.qclear$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;
                sendKawaApiMessage("/queue/clear", "POST");
                sendApiMessage("/admin/push", "POST", [
                    "type" => "queue",
                    "data" => json_encode(["action" => "clear"], JSON_NUMERIC_CHECK),
                ], ["Authorization: " . ($originalSender["identified"] ? $db->getUserApiKey($originalSender["name"], BOT_KEYID) : DEFAULT_API_KEY)]);
                sendIRCMessage("Cleared queue", $answer);
            },
        ],

        [
            "targets" => [BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.qpop$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;
                $q = sendKawaApiMessage("/queue");
                if(count($q) > 0){
                    //sendKawaApiMessage("/queue/tail", "DELETE");
                    sendApiMessage("/api/queue/tail", "DELETE", null, ["Authorization: " . ($originalSender["identified"] ? $db->getUserApiKey($originalSender["name"], BOT_KEYID) : DEFAULT_API_KEY)]);
                    $t = array_pop($q);
                    $t = $db->getTrackById($t["id"]);
                    sendIRCMessage("Unqueued " . formatTrackName($t), $answer);
                }else{
                    sendIRCMessage("Queue? There is no queue.", $answer);
                }
            },
        ],

        [
            "targets" => [BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.qshift$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;
                $q = sendKawaApiMessage("/queue");
                if(count($q) > 0){
                    //sendKawaApiMessage("/queue/head", "DELETE");
                    sendApiMessage("/api/queue/head", "DELETE", null, ["Authorization: " . ($originalSender["identified"] ? $db->getUserApiKey($originalSender["name"], BOT_KEYID) : DEFAULT_API_KEY)]);
                    $t = array_shift($q);
                    $t = $db->getTrackById($t["id"]);
                    sendIRCMessage("Unqueued " . formatTrackName($t), $answer);
                }else{
                    sendIRCMessage("Queue? There is no queue.", $answer);
                }
            },
        ],

        [
            "targets" => [BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.(q?skip|next|nope)$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;
                $np = $db->getNowPlaying();
                sendKawaApiMessage("/skip", "POST");
                //sendApiMessage("/api/skip", "GET");
                sendIRCMessage("Skipped " . formatTrackName($np), $answer);
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.(np|nowplaying|now)$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;
                $np = $db->getNowPlaying();
                sendIRCMessage("Now playing: " . formatTrackName($np, $np["started"]), $answer);
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.(l|listeners|who|listening)$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                $l = sendApiMessage("/api/listeners", "GET", null, ["Authorization: " . DEFAULT_API_KEY]);
                foreach($l["named_listeners"] as $k => $n){
                    $l["named_listeners"][$k] = removePing(cleanupCodes($n));
                }
                $msg = "Total ". $l["num_listeners"] . (count($l["named_listeners"]) > 0 ? (count($l["named_listeners"]) > 15 ? " listener(s), including ".count($l["named_listeners"])." named." : " listener(s), including " . implode(", ", $l["named_listeners"])) : " listener(s).");
                sendIRCMessage($msg, $answer);
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.(history|h|played|prev|previous)$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;
                $q = $db->getHistory(MAX_RESULTS + 1);
                array_shift($q);
                foreach($q as $req){
                    sendIRCMessage("Recently played: " . formatTrackName($req), $answer);
                }
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.(q|queue)$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;
                $q = sendKawaApiMessage("/queue");
                $c = 0;
                foreach($q as $req){
                    if($c >= MAX_RESULTS){
                        break;
                    }
                    $source = $req["source"] ?? null;
                    $req = $db->getTrackById($req["id"]);
                    $req["source"] = $source;
                    ++$c;
                    sendIRCMessage("Queue #$c: " . formatTrackName($req), $answer);
                }
                if(count($q) > MAX_RESULTS){
                    sendIRCMessage("... and ". (count($q) - MAX_RESULTS) ." more.", $answer);
                }
                if(count($q) === 0){
                    $json = sendKawaApiMessage("/random");
                    if(is_array($json)){
                        sendIRCMessage("Queue is empty! Up next: " . formatTrackName($json), $answer);
                    }else{
                        sendIRCMessage("Queue is empty!", $answer);
                    }
                }
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_NONE,
            "match" => "#^\\.(source|code|pr)$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                sendIRCMessage("You can read the listing of parts that compose radio on https://". SITE_HOSTNAME ."/help.html#source, and also get source code if available.", $answer);
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_NONE,
            "match" => "#^\\.(help|info|version)$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db, $torrentIndex;
                $stats = $db->getStats();
                $tStats = $torrentIndex->getStats();
                sendIRCMessage(
                    "Full help at https://". SITE_HOSTNAME ."/help.html :: Listen https://". SITE_HOSTNAME ." :: ".
                    number_format($stats["total_count"]) ." tracks, ".secondsToTime($stats["total_duration"]) ." :: ".
                    number_format($stats["total_favorites"]) ." favorites :: ".number_format($stats["total_plays"]) ." plays :: ".
                    number_format($stats["total_albums"]) ." albums :: ".number_format($stats["total_artists"]) ." artists :: ".
                    number_format($tStats["ab_groups"]).($tStats["ab_empty_groups"] > 0 ? " (".$tStats["ab_empty_groups"].")" : "")." AB groups, ".
                    number_format($tStats["jps_groups"]).($tStats["jps_empty_groups"] > 0 ? " (".$tStats["jps_empty_groups"].")" : "").
                    " JPS groups :: ".BOT_NICK." version " . substr(trim(file_get_contents(".version")), 0, 8), $answer);
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_ADMIN,
            "match" => "#^\\.createtag[ \t]+\\#([^ \t]+)$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;

                $tagName = strtolower($matches[1]);
                $tagId = $db->getTagIdByName($tagName);

                if($tagId !== null){
                    sendIRCMessage("Tag #". $tagName ." already exists", $answer);
                    return;
                }

                if($originalSender["permission"] < PERMISSION_SUPERADMIN and preg_match("/^(ab|jps|red|bbt)[tgsa]\\-([0-9]+)$/", $tagName) > 0){
                    sendIRCMessage("Tag #". $tagName ." is not allowed", $answer);
                    return;
                }

                $db->createTag($tagName);

                sendIRCMessage("Created tag #$tagName", $answer);
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_MOD,
            "match" => "#^\\.untag[ \t]+\\#([^ \t]+)[ \t]+(.+)$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;

                $tagName = strtolower($matches[1]);
                $tagId = $db->getTagIdByName($tagName);

                if($tagId === null){
                    sendIRCMessage("Tag #". $tagName ." does not exist", $answer);
                    return;
                }

                if($originalSender["permission"] < PERMISSION_SUPERADMIN and preg_match("/^(ab|jps|red|bbt)[tgsa]\\-([0-9]+)$/", $tagName) > 0){
                    sendIRCMessage("Tag #". $tagName ." is not allowed", $answer);
                    return;
                }

                $np = $db->getTrackByHash($matches[2]);
                if(isset($np["hash"])){
                    $results = [$np];
                }else{
                    $results = sendApiMessage("/api/search?limit=5000&q=" . urlencode($matches[2]), "GET", null, ["Authorization: " . DEFAULT_API_KEY]);
                }

                if(count($results) > 5000){
                    sendIRCMessage("Too many results! (".count($results)." > 5000)", $answer);
                    return;
                }


                $tags = 0;
                foreach($results as $res){
                    if(in_array($tagName, $res["tags"], true)){
                        $db->removeTagging($res["id"], $tagId);
                        ++$tags;
                    }
                }

                sendIRCMessage("Untagged $tags tracks with #$tagName", $answer);
            },
        ],


        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_PUSER,
            "match" => "#^\\.(update|refresh)[ \t]+\\#?([^ \t]+)$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db, $torrentIndex;


                $group = null;
                if(preg_match("/^(http|https):\\/\\/jpopsuki\\.eu\\/(torrents|torrents2)\\.php\\?.*$/", trim($matches[2])) > 0){
                    $url = parse_url($matches[2]);
                    $entries = null;
                    @parse_str($url["query"], $entries);
                    if(isset($entries["id"])){
                        $matches[2] = "jpsg-" . $entries["id"];
                    }else if(isset($entries["torrentid"])){
                        $matches[2] = "jpst-" . $entries["id"];
                    }
                }else if(preg_match("/^(http|https):\\/\\/animebytes\\.tv\\/(torrents|torrents2)\\.php\\?.*$/", trim($matches[2])) > 0){
                    $url = parse_url($matches[2]);
                    $entries = null;
                    @parse_str($url["query"], $entries);
                    if(isset($entries["id"])){
                        $matches[2] = "abg-" . $entries["id"];
                    }else if(isset($entries["torrentid"])){
                        $matches[2] = "abt-" . $entries["id"];
                    }
                }else if(preg_match("/^(http|https):\\/\\/animebytes\\.tv\\/torrent\\/([0-9]+)\\/group$/", trim($matches[2]), $matches2) > 0){
                    $matches[2] = "abt-" . $matches2[2];
                }

                $tagName = strtolower($matches[2]);
                if(preg_match("/^(ab|jps)([tg])\\-([0-9]+)$/", $tagName, $m) > 0){
                    $group = null;
                    $type = null;
                    if($m[1] === "ab"){
                        $type = 1;
                    }else if($m[1] === "jps"){
                        $type = 2;
                    }

                    if($m[2] === "t"){
                        $torrent = $torrentIndex->getTorrentBySiteId($m[3], $type);
                        $group = $torrent->getGroupById($torrent["group_id"]);
                    }else if($m[2] === "g"){
                        $group = $torrentIndex->getGroupBySiteId($m[3], $type);
                    }

                    if($group !== null){
                        if(isset($group["metadata"]["forceRefresh"])){
                            sendIRCMessage("Group is still pending next fetch.", $answer);
                        }else{
                            $group["metadata"]["forceRefresh"] = true;
                            $torrentIndex->updateGroup($group["id"], $group["metadata"]);
                            sendIRCMessage("Group will have its metadata updated next fetch.", $answer);
                        }
                    }else{
                        sendIRCMessage("Unknown/invalid update group #$tagName", $answer);
                    }
                }else{
                    sendIRCMessage("Unknown/invalid update group #$tagName", $answer);
                }
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_MOD,
            "match" => "#^\\.tag[ \t]+\\#([^ \t]+)[ \t]+(.+)$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;

                $tagName = strtolower($matches[1]);
                $tagId = $db->getTagIdByName($tagName);

                if($tagId === null){
                    sendIRCMessage("Tag #". $tagName ." does not exist", $answer);
                    return;
                }

                if($originalSender["permission"] < PERMISSION_SUPERADMIN and preg_match("/^(ab|jps|red|bbt)[tgsa]\\-([0-9]+)$/", $tagName) > 0){
                    sendIRCMessage("Tag #". $tagName ." is not allowed", $answer);
                    return;
                }

                $np = $db->getTrackByHash($matches[2]);
                if(isset($np["hash"])){
                    $results = [$np];
                }else{
                    $results = sendApiMessage("/api/search?limit=5000&q=" . urlencode($matches[2]), "GET", null, ["Authorization: " . DEFAULT_API_KEY]);
                }

                if(count($results) > 5000){
                    sendIRCMessage("Too many results! (".count($results)." > 5000)", $answer);
                    return;
                }


                $tags = 0;
                foreach($results as $res){
                    if(!in_array($tagName, $res["tags"], true)){
                        $db->addTagging($res["id"], $tagId);
                        ++$tags;
                    }
                }

                sendIRCMessage("Tagged $tags tracks with #$tagName", $answer);
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.(dupes?|duplicates?)([ \t]+(.+)|())$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db, $lastResult, $lastResultIndex;

                if($matches[3] === ""){
                    $t = $db->getNowPlaying();
                }else{
                    $t = $db->getTrackByHash($matches[3]);
                }
                if(isset($t["hash"])){
                    $albumName = addslashes($t["album"]);
                    $artistName = addslashes($t["artist"]);
                    $title = addslashes($t["title"]);
                    $durationStart = $t["duration"] - 3;
                    $durationEnd = $t["duration"] + 3;
                    $hash = addslashes($t["hash"]);
                    $audioHash = "";
                    if(isset($t["audio_hash"]) and $t["audio_hash"] !== null){
                        $audioHash = " OR audio='" . $t["audio_hash"] ."'";
                    }
                    $query = "(((title='$title' AND duration>$durationStart AND duration<$durationEnd AND ((album:'$albumName') OR (artist:'$artistName') OR (duration=".$t["duration"].")))$audioHash))";
                    $results = sendApiMessage("/api/search?limit=5000&orderBy=score&orderDirection=desc&q=" . urlencode($query), "GET", null, ["Authorization: " . DEFAULT_API_KEY]);

                    $lastResult[$originalSender["id"]] = [];
                    foreach($results as $res){
                        $lastResult[$originalSender["id"]][] = $res;
                        if(count($lastResult[$originalSender["id"]]) >= (MAX_RESULTS + 1)){
                            continue;
                        }
                        sendIRCMessage(FORMAT_COLOR_YELLOW . FORMAT_UNDERLINE . count($lastResult[$originalSender["id"]]) . FORMAT_RESET .". ". formatTrackName($res), $answer);
                        $lastResultIndex = count($lastResult[$originalSender["id"]]) - 1;
                    }

                    if(count($results) > MAX_RESULTS){
                        sendIRCMessage("... and ". number_format(count($results) - MAX_RESULTS) ." more.", $answer);
                    }
                }else{
                    sendIRCMessage("Could not find track", $answer);
                }
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.(s|search|req|r|find)([ \t]+(.+)|())$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db, $lastResult, $lastResultIndex;
                $np = $db->getTrackByHash($matches[3]);
                if(isset($np["hash"])){
                    $results = [$np];
                }else{
                    $results = sendApiMessage("/api/search?limit=5000&orderBy=score&orderDirection=desc&q=" . urlencode($matches[3]), "GET", null, ["Authorization: " . DEFAULT_API_KEY]);
                }
                $lastResult[$originalSender["id"]] = [];

                if(count($results) === 1 and ($matches[1] === "req" or $matches[1] === "r")){
                    $req = $results[0];
                    $req = $db->getTrackById($req["id"]);
                    sendApiMessage("/api/request/" . $req["hash"], "GET", null, ["Authorization: " . ($originalSender["identified"] ? $db->getUserApiKey($originalSender["name"], BOT_KEYID) : DEFAULT_API_KEY)]);
                    sendIRCMessage("Queued: " . formatTrackName($req), $answer);
                }else{
                    foreach($results as $res){
                        //$res = $db->getTrackById($res["id"]);
                        $lastResult[$originalSender["id"]][] = $res;
                        if(count($lastResult[$originalSender["id"]]) >= (MAX_RESULTS + 1)){
                            continue;
                        }
                        sendIRCMessage(FORMAT_COLOR_YELLOW . FORMAT_UNDERLINE . count($lastResult[$originalSender["id"]]) . FORMAT_RESET .". ". formatTrackName($res), $answer);
                        $lastResultIndex = count($lastResult[$originalSender["id"]]) - 1;
                    }

                    if(count($results) > MAX_RESULTS){
                        sendIRCMessage("... and ". number_format(count($results) - MAX_RESULTS) ." more.", $answer);
                    }
                }

                if(!is_array($results) or count($results) === 0){
                    if(mt_rand(0, 9) == 0){
                        if(preg_match("/[\u{3000}-\u{303f}\u{3040}-\u{309f}\u{30a0}-\u{30ff}\u{ff00}-\u{ff9f}\u{4e00}-\u{9faf}\u{3400}-\u{4dbf}]/u", $matches[3]) > 0){
                            sendIRCMessage("No results. " . FORMAT_ITALIC . randomCase("Did you try searching in Romaji?"), $answer);
                        }else{
                            sendIRCMessage("No results. " . FORMAT_ITALIC . randomCase("Did you try searching in Japanese?"), $answer);
                        }
                    }else{
                        if(preg_match("/[\u{3000}-\u{303f}\u{3040}-\u{309f}\u{30a0}-\u{30ff}\u{ff00}-\u{ff9f}\u{4e00}-\u{9faf}\u{3400}-\u{4dbf}]/u", $matches[3]) > 0){
                            sendIRCMessage("No results. Did you try searching in Romaji?", $answer);
                        }else{
                            sendIRCMessage("No results. Did you try searching in Japanese?", $answer);
                        }
                    }
                }
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.(more|m|expand|continue|advance|panzer vor|motto)([ \t]+(.+)|())$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db, $lastResult, $lastResultIndex;
                if(isset($lastResult[$originalSender["id"]]) and count($lastResult[$originalSender["id"]]) > 0 and $lastResultIndex < count($lastResult[$originalSender["id"]])){
                    $startIndex = $lastResultIndex;
                    $index = 0;
                    foreach($lastResult[$originalSender["id"]] as $res){

                        if($index <= $startIndex){
                            ++$index;
                            continue;
                        }
                        if(($index - $startIndex) >= MAX_RESULTS){
                            break;
                        }
                        sendIRCMessage(FORMAT_COLOR_YELLOW . FORMAT_UNDERLINE . ($index + 1) . FORMAT_RESET .". ". formatTrackName($res), $answer);
                        $lastResultIndex = $index;
                        ++$index;
                    }
                }
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.?(([ \t]*[0-9]+[ \t]*,?)+)$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db, $lastResult, $lastResultIndex;
                foreach(explode(",", $matches[1]) as $id){
                    $id = trim($id);
                    if(isset($lastResult[$originalSender["id"]]) and count($lastResult[$originalSender["id"]]) > 0 and (((int)$id) - 1) <= $lastResultIndex){
                        $index = ((int)$id) - 1;
                        if(isset($lastResult[$originalSender["id"]][$index])){
                            $req = $db->getTrackById($lastResult[$originalSender["id"]][$index]["id"]);
                            if($req !== null){
                                sendApiMessage("/api/request/" . $req["hash"], "GET", null, ["Authorization: " . ($originalSender["identified"] ? $db->getUserApiKey($originalSender["name"], BOT_KEYID) : DEFAULT_API_KEY)]);
                                $req = $db->getTrackById($req["id"]);
                                sendIRCMessage("Queued: " . formatTrackNameShort($req), $answer);
                            }
                            unset($lastResult[$originalSender["id"]][$index]);
                        }
                    }
                }
                if(isset($lastResult[$originalSender["id"]]) and count($lastResult[$originalSender["id"]]) > 0){
                    $lastResult[$originalSender["id"]] = [];
                    $lastResultIndex = 0;
                }
            }
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.(fetch|stewtime)([ \t]+https?://[^ \t]+|)$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db, $lastResult, $lastResultIndex;
                $f = null;
                $track = null;
                $badResults = [];
                $resss = [];
                $lastResult[$originalSender["id"]] = [];
                if($matches[1] === "stewtime" or preg_match("#^(https?://radio\\.stew\\.moe/stream/.+)$#iu", trim($matches[2]), $m) > 0){
                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, "https://radio.stew.moe/api/playing");
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $msg = curl_exec($ch);
                    curl_close($ch);
                    $json = @json_decode($msg, true);
                    if(is_array($json)){
                        $f = $json;
                        $f["artist_raw"] = $f["artist"];
                        $f["album_artist_raw"] = $f["artist"];
                        $track = $db->getTrackByHash($f["hash"]);
                        if($track !== null){
                            $lastResult[$originalSender["id"]][] = $track;
                        }
                    }
                }else if(preg_match("#^(https?://(www\\.|)youtube\\.com/watch)#", trim($matches[2]), $m) > 0){
                    $url = parse_url(trim($matches[2]));
                    parse_str($url["query"], $query);
                    $f = getYoutubeMetadata($query["v"]);
                }else if(preg_match("#^(https?://youtu\\.be/)([^\\?/]+)#", trim($matches[2]), $m) > 0){
                    $f = getYoutubeMetadata($m[2]);
                }else if(preg_match("#^(https?://(stream\\.|)r\\-a\\-d\\.io/?.*)$#iu", trim($matches[2]), $m) > 0){
                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, "https://r-a-d.io/api");
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $msg = curl_exec($ch);
                    curl_close($ch);
                    $json = @json_decode($msg, true);
                    if(is_array($json) and isset($json["main"]["np"])){
                        $data = explode(" - ", $json["main"]["np"]);
                        $artist = array_shift($data);
                        $f = [
                            "artist" => $artist,
                            "artist_raw" => $artist,
                            "album_artist_raw" => $artist,
                            "title" => implode(" - ", $data),
                            "album" => "[Unknown Album]",
                            "duration" => floor($json["main"]["end_time"] - $json["main"]["start_time"]),
                        ];
                    }
                }else if(preg_match("#^(https?://edenofthewest\\.com/?.*)$#iu", trim($matches[2]), $m) > 0){
                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, "https://www.edenofthewest.com/public/eden_radio");
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $msg = curl_exec($ch);
                    curl_close($ch);
                    if(preg_match('/props: (\\{.*)}\\);/m', $msg, $m2) > 0){
                        $json = @json_decode($m2[1], true);
                        if(is_array($json) and isset($json["initial_now_playing"]["now_playing"])){
                            $data = $json["initial_now_playing"]["now_playing"];
                            $f = [
                                "artist" => $data["song"]["artist"],
                                "artist_raw" => $data["song"]["artist"],
                                "album_artist_raw" => null,
                                "title" => $data["song"]["title"],
                                "album" => $data["song"]["album"],
                                "duration" => $data["duration"],
                            ];
                        }
                    }

                }else if(preg_match("#^(https?://jpopsuki\\.(eu|fm)(|:[0-9]+)/?.*)$#iu", trim($matches[2]), $m) > 0){
                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, "http://jpopsuki.fm:2199/external/rpc.php?m=streaminfo.get&username=jpopsuki&charset=&mountpoint=&rid=jpopsuki");
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $msg = curl_exec($ch);
                    curl_close($ch);
                    $json = @json_decode($msg, true);
                    if(is_array($json) and isset($json["data"][0])){
                        $info = $json["data"][0];
                        $f = [
                            "artist" => $info["track"]["artist"],
                            "artist_raw" => $info["track"]["artist"],
                            "album_artist_raw" => $info["track"]["artist"],
                            "title" => $info["track"]["title"],
                            "album" => $info["track"]["album"],
                            "duration" => null,
                        ];
                    }
                }else if(preg_match("#^(https?://(www\\.|)gensokyoradio\\.net/?.*)$#iu", trim($matches[2]), $m) > 0){
                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, "https://gensokyoradio.net/xml/");
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $msg = curl_exec($ch);
                    curl_close($ch);

                    $title = trim(html_entity_decode(getString($msg, "<TITLE>", "</TITLE>"), ENT_QUOTES | ENT_HTML5));
                    $album = trim(html_entity_decode(getString($msg, "<ALBUM>", "</ALBUM>"), ENT_QUOTES | ENT_HTML5));
                    $artist = trim(html_entity_decode(getString($msg, "<ARTIST>", "</ARTIST>"), ENT_QUOTES | ENT_HTML5));
                    $duration = (int) trim(html_entity_decode(getString($msg, "<DURATION>", "</DURATION>"), ENT_QUOTES | ENT_HTML5));

                    $f = [
                        "artist" => $artist,
                        "artist_raw" => $artist,
                        "album_artist_raw" => $artist,
                        "title" => $title,
                        "album" => $album,
                        "duration" => $duration,
                    ];
                }/*else if(preg_match("#^(https?://(www\\.|)animenfo\\.com/?.*)$#iu", trim($matches[2]), $m) > 0){
                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, "https://www.animenfo.com/radio/nowplaying.php");
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $msg = curl_exec($ch);
                    curl_close($ch);

                    $information = getString($msg, "cur-playing\">", "favourite-container");
                    $title = trim(html_entity_decode(getString($information, "Title:</span>", "<br"), ENT_QUOTES | ENT_HTML5));
                    $album = trim(html_entity_decode(getString($information, "data-search-album >", "</"), ENT_QUOTES | ENT_HTML5));
                    $artist = trim(html_entity_decode(getString($information, "data-search-artist >", "</"), ENT_QUOTES | ENT_HTML5));
                    $duration = (int) trim(html_entity_decode(getString($information, 'id="np_time" rel="', '">'), ENT_QUOTES | ENT_HTML5));

                    $f = [
                        "artist" => $artist,
                        "artist_raw" => $artist,
                        "album_artist_raw" => $artist,
                        "title" => $title,
                        "album" => $album,
                        "duration" => $duration,
                    ];
                }*/else if($matches[1] === "fetch" and $matches[2] !== ""){
                    $url = trim($matches[2]);
                    if(preg_match("#^(https?://mygi\\.ga/.+)[/\\?]embed$#iu", $url, $m) > 0){
                        $url = $m[1];
                    }else if(preg_match("#^(https?://mygi\\.ga)/embed(/.+)$#iu", $url, $m) > 0){
                        $url = $m[1] . $m[2];
                    }else if(preg_match("#^(https?://listen\\.moe/kpop/?.*)$#iu", $url, $m) > 0){
                        $url = "https://listen.moe/kpop/stream";
                    }else if(preg_match("#^(https?://listen\\.moe/?.*)$#iu", $url, $m) > 0){
                        $url = "https://listen.moe/stream";
                    }

                    $f = getMediaMetadata($url);
                }

                if($f !== null and $f["title"] !== ""){


                    $sql3 = <<<SQL
SELECT
	songs.id AS id,
	songs.hash AS hash,
	songs.title AS title,
    (SELECT artists.name FROM artists WHERE songs.artist = artists.id LIMIT 1) AS artist,
    (SELECT albums.name FROM albums WHERE songs.album = albums.id LIMIT 1) AS album,    
	songs.path AS path,
    songs.cover AS cover,
    songs.lyrics AS lyrics,
	songs.duration AS duration,
	songs.status AS status,
	songs.play_count AS play_count,
    songs.song_metadata AS song_metadata,
	array_to_json(ARRAY(SELECT tags.name FROM tags JOIN taggings ON (taggings.tag = tags.id) WHERE taggings.song = songs.id)) AS tags,
	array_to_json(ARRAY(SELECT users.name FROM users JOIN favorites ON (favorites.user_id = users.id) WHERE favorites.song = songs.id)) AS favored_by
FROM songs
WHERE (songs.title ILIKE $1 OR songs.album IN(SELECT id FROM albums WHERE name ILIKE $3) OR songs.artist IN(SELECT id FROM artists WHERE name ILIKE $4) OR songs.album IN(SELECT id FROM albums WHERE name ILIKE $5)) AND songs.duration <= ($2 + 3) AND songs.duration >= ($2 - 3)
;
SQL;

                    $sql4 = <<<SQL
SELECT
	songs.id AS id,
	songs.hash AS hash,
	songs.title AS title,
    (SELECT artists.name FROM artists WHERE songs.artist = artists.id LIMIT 1) AS artist,
    (SELECT albums.name FROM albums WHERE songs.album = albums.id LIMIT 1) AS album,    
	songs.path AS path,
    songs.cover AS cover,
    songs.lyrics AS lyrics,
	songs.duration AS duration,
	songs.status AS status,
	songs.play_count AS play_count,
    songs.song_metadata AS song_metadata,
	array_to_json(ARRAY(SELECT tags.name FROM tags JOIN taggings ON (taggings.tag = tags.id) WHERE taggings.song = songs.id)) AS tags,
	array_to_json(ARRAY(SELECT users.name FROM users JOIN favorites ON (favorites.user_id = users.id) WHERE favorites.song = songs.id)) AS favored_by
FROM songs
WHERE (songs.title ILIKE $1 OR songs.album IN(SELECT id FROM albums WHERE name ILIKE $3) OR songs.artist IN(SELECT id FROM artists WHERE name ILIKE $4) OR songs.album IN(SELECT id FROM albums WHERE name ILIKE $5) OR (songs.duration <= ($2 + 3) AND songs.duration >= ($2 - 3)))
;
SQL;
                    $removeChars = [":", "'", "\"", "-", "~", " ", ".", "[", "]", "(", ")", "_", "#", "�", "?", "!", "/", ";", "+", "=", "*"];
                    $removeRegex = [
                        "#(part|cd|disc|box|ost|cdbox)[0-9i]+#iu",
                        "#(original|complete)?soundtrack[0-9i]*#iu",
                        "#(remaster|remastered)#iu",
                    ];
                    $searchTitle = trim(preg_replace($removeRegex, "", str_replace($removeChars, "", strtolower($f["title"]))));

                    if($f["artist"] === "[Unknown Artist]" and isset($f["album_artist"])){
                        $f["artist"] = $f["album_artist"];
                    }

                    $time = " [" . floor($f["duration"] / 60) . ":" . str_pad($f["duration"] % 60, 2, "0", STR_PAD_LEFT) . "]";
                    sendIRCMessage("Track information: " . FORMAT_BOLD . cleanupCodes($f["title"]) . FORMAT_RESET . " by " . FORMAT_BOLD . cleanupCodes($f["artist"]) . FORMAT_RESET . " from " .  FORMAT_BOLD . cleanupCodes($f["album"]) . FORMAT_RESET . $time, $answer);

                    if($track === null){

                        $result = pg_query_params($db->dbconn, $sql3, [$searchTitle, $f["duration"], $f["artist_raw"], $f["album_artist_raw"], $f["album"]]);
                        $result2 = pg_query_params($db->dbconn, $sql4, [$searchTitle, $f["duration"], $f["artist_raw"], $f["album_artist_raw"], $f["album"]]);
                        while($row = pg_fetch_array($result2, null, PGSQL_ASSOC)){

                            $title1 = trim(preg_replace($removeRegex, "", str_replace($removeChars, "", strtolower($row["title"]))));
                            $title2 = trim(preg_replace($removeRegex, "", str_replace($removeChars, "", strtolower($f["title"]))));
                            $album1 = trim(preg_replace($removeRegex, "", str_replace($removeChars, "", strtolower($row["album"]))));
                            $album2 = trim(preg_replace($removeRegex, "", str_replace($removeChars, "", strtolower($f["album"]))));
                            $artist1 = trim(preg_replace($removeRegex, "", str_replace($removeChars, "", strtolower($row["artist"]))));
                            $artist2 = trim(preg_replace($removeRegex, "", str_replace($removeChars, "", strtolower($f["artist"]))));
                            if(
                                (
                                    $title1 === $title2
                                    or (
                                    (
                                        stripos($title1, $title2) !== false
                                        /*or stripos($title2, $title1) !== false*/
                                    )
                                    )
                                )
                                and (
                                    $album1 === $album2
                                    or $artist1 === $artist2
                                    or stripos($album1, $album2) !== false
                                    or stripos($album2, $album1) !== false
                                    or stripos($artist1, $artist2) !== false
                                    or stripos($artist2, $artist1) !== false
                                    or abs($row["duration"] - $f["duration"]) <= 3
                                )
                            ){
                                $res = $db->getTrackById($row["id"]);
                                $badResults[] = $res;
                            }
                        }
                        while($row = pg_fetch_array($result, null, PGSQL_ASSOC)){

                            $title1 = trim(preg_replace($removeRegex, "", str_replace($removeChars, "", strtolower($row["title"]))));
                            $title2 = trim(preg_replace($removeRegex, "", str_replace($removeChars, "", strtolower($f["title"]))));
                            $album1 = trim(preg_replace($removeRegex, "", str_replace($removeChars, "", strtolower($row["album"]))));
                            $album2 = trim(preg_replace($removeRegex, "", str_replace($removeChars, "", strtolower($f["album"]))));
                            $artist1 = trim(preg_replace($removeRegex, "", str_replace($removeChars, "", strtolower($row["artist"]))));
                            $artist2 = trim(preg_replace($removeRegex, "", str_replace($removeChars, "", strtolower($f["artist"]))));
                            if(
                                (
                                    $title1 === $title2
                                    or (
                                    (
                                        stripos($title1, $title2) !== false
                                        /*or stripos($title2, $title1) !== false*/
                                    )
                                    )
                                )
                                and (
                                    $album1 === $album2
                                    or $artist1 === $artist2
                                    or stripos($album1, $album2) !== false
                                    or stripos($album2, $album1) !== false
                                    or stripos($artist1, $artist2) !== false
                                    or stripos($artist2, $artist1) !== false
                                )
                            ){
                                $res = $db->getTrackById($row["id"]);
                                $lastResult[$originalSender["id"]][] = $res;
                                $resss[$res["id"]] = $res;
                            }else if($title1 === $title2){
                                $res = $db->getTrackById($row["id"]);
                                $badResults[] = $res;
                            }
                        }


                        foreach($badResults as $bad){
                            if(isset($resss[$bad["id"]])){
                                continue;
                            }
                            $lastResult[$originalSender["id"]][] = $bad;
                            $resss[$bad["id"]] = $bad;
                        }
                    }

                    if(count($lastResult[$originalSender["id"]]) === 0){
                        sendIRCMessage("No results found.", $answer);
                    }


                    uasort($lastResult, "sortSongs");

                    for($i = 0; $i < count($lastResult[$originalSender["id"]]); ++$i){
                        if($i >= MAX_RESULTS){
                            sendIRCMessage("... and ". number_format(count($lastResult[$originalSender["id"]]) - MAX_RESULTS) ." results more.", $answer);
                            break;
                        }
                        sendIRCMessage(FORMAT_COLOR_YELLOW . FORMAT_UNDERLINE . ($i + 1) . FORMAT_RESET .". ". formatTrackName($lastResult[$originalSender["id"]][$i]), $answer);
                        $lastResultIndex = $i;
                    }
                }else{
                    sendIRCMessage("Could not fetch metadata from URL.", $answer);
                }
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^[\\.!]nana#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;
                $np = null;

                $hashes = [
                    '7c57e488c8c1',
                    'b92a0114958e',
                    '46a2911eb456',
                    '691e1d8a38da',
                    '8fe559c07363',
                    '0f8c3c18242d',
                    'a1a5c83493f2',
                    '81d7bbbacd42',
                    'https://mei.animebytes.tv/D46VG0mwINv.png 🐟',
                    '18e18823107f',
                    '9f4ab07bede1',
                    '4379a84c66f9',
                    '3be87f1f3252'
                ];

                $hash = $hashes[random_int(0, count($hashes) - 1)];


                $np = $db->getTrackByHash($hash);

                if($np !== null and isset($np["hash"])){
                    sendIRCMessage(formatTrackName($np) . " ~ Player https://". SITE_HOSTNAME ."/player/hash/" .substr($np["hash"], 0, 12), $answer);
                }else{
                    sendIRCMessage($hash, $answer);
                }
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL, "#animebytes"],
            "permission" => PERMISSION_NONE,
            "match" => "#^[\\.!]dexx$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                $dexxList = [
                    'https://mei.animebytes.tv/iSFyqIcWe52.jpg',
                    'https://mei.animebytes.tv/j9d7hxAEXvc.png',
                    'https://mei.animebytes.tv/G6ODFRbpeZb.png',
                    'https://mei.animebytes.tv/zdUp2UnqfDE.png',
                    'https://mei.animebytes.tv/ADujF4bKgr6.png',
                    'https://mei.animebytes.tv/mXM3UKAThcb.png',
                    'https://mei.animebytes.tv/nxryVLieoMM.jpg',
                    'https://mei.animebytes.tv/yCFvrqORK6Y.jpg',
                    'https://mei.animebytes.tv/qleNIVBVJYj.png',
                    'https://mei.animebytes.tv/d0IUirWK1d5.jpg',
                    'https://mei.animebytes.tv/MqcfxqlG7cw.jpg',
                    'https://mei.animebytes.tv/JOhXujD7KqF.png',
                    'https://mei.animebytes.tv/ET3xPBbCVaK.png',
                    'https://mei.animebytes.tv/dkolat4lSGk.png',
                    'https://mei.animebytes.tv/IWZ1mr197ec.png',
                    'https://mei.animebytes.tv/qLNUTYq764t.jpg',
                    'https://mei.animebytes.tv/Qqp6iZ1IP7Z.png',
                    'https://mei.animebytes.tv/g22vWJWmjGu.png',
                    'https://mei.animebytes.tv/xwtTcqg5ISd.png',
                    'https://mei.animebytes.tv/gdK0xVFTP4Y.png',
                    'https://mei.animebytes.tv/XOuQrTFc8QA.png',
                    'https://mei.animebytes.tv/Jej0C375w8N.jpg',
                    'https://mei.animebytes.tv/FzOdQyjpIsF.jpg',
                    'https://mei.animebytes.tv/SbSSWJMnTma.png',
                    'https://mei.animebytes.tv/QCr1zexHAjU.jpg',
                    'https://mei.animebytes.tv/yCumo1yPmDp.jpg',
                    'https://mei.animebytes.tv/WS7ZhkH6u8m.jpg',
                    'https://mei.animebytes.tv/rsVzHA2jYa8.jpg'
                ];

                global $dessLimiter;
                if(!isset($dessLimiter)){
                    $dessLimiter[$originalSender["id"]] = 0;
                }
                if(time() >= $dessLimiter[$originalSender["id"]]){
                    $dessLimiter[$originalSender["id"]] = time() + 298;
                    sendIRCMessage("Dess! 🔞 " . $dexxList[random_int(0, count($dexxList) - 1)], $answer);
                }else{
                    sendIRCMessage("Too much dess. Wait another " . ($dessLimiter[$originalSender["id"]] - time()) . " second(s).", $originalSender["user"]);
                }
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL, "#animebytes"],
            "permission" => PERMISSION_NONE,
            "match" => "#^[\\.!]dess$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                $dessList = [
                    'https://mei.animebytes.tv/hFijVc6sus5.png',
                    'https://mei.animebytes.tv/bAZLbgRcgjL.png',
                    'https://mei.animebytes.tv/e3gkfjO9sXE.png',
                    'https://mei.animebytes.tv/Zdq45NLPplR.png',
                    'https://mei.animebytes.tv/FcOKbzJgWjq.jpg',
                    'https://mei.animebytes.tv/QnlNvXTvbpm.jpg',
                    'https://mei.animebytes.tv/Mb5guYvnOGI.jpg',
                    'https://mei.animebytes.tv/ZxNpQg5cKB7.jpg',
                    'https://mei.animebytes.tv/NBPr0fdqmwT.jpg',
                    'https://mei.animebytes.tv/ZhVcHeHuczt.jpg',
                    'https://mei.animebytes.tv/JrOp0hEL0hV.jpg',
                    'https://mei.animebytes.tv/YHZPq1qqfdu.jpg',
                    'https://mei.animebytes.tv/uLEPekLO1Fj.jpg',
                    'https://mei.animebytes.tv/eXk2574ugtn.png',
                    'https://mei.animebytes.tv/k1ajpCB2ZuD.jpg',
                    'https://mei.animebytes.tv/Dz3fweiIqwQ.jpg',
                    'https://mei.animebytes.tv/swqMooOXZgH.jpg',
                    'https://mei.animebytes.tv/nzlWwZnUZSC.jpg',
                    'https://mei.animebytes.tv/CtmTz3Gu7cL.png',
                    'https://mei.animebytes.tv/kUiw0Hwh12X.jpg',
                    'https://mei.animebytes.tv/D2FNKHRgbjP.jpg',
                    'https://mei.animebytes.tv/K8JI8hfQPec.jpg',
                    'https://mei.animebytes.tv/MO6EBp9UyOF.jpg',
                    'https://mei.animebytes.tv/CCuxvHLgV4m.jpg',
                    'https://mei.animebytes.tv/qh3NPUGYseY.jpg',
                    'https://mei.animebytes.tv/m2zZn9tgkFw.jpg',
                    'https://mei.animebytes.tv/Cy3kJbmD2HJ.jpg',
                    'https://mei.animebytes.tv/HqjRH6PUFgJ.jpg',
                    'https://mei.animebytes.tv/GSCJthlYc5f.png',
                    'https://mei.animebytes.tv/5Xn7PLkYkeq.png',
                    'https://mei.animebytes.tv/dQcUDWu7m3O.jpg',
                    'https://mei.animebytes.tv/qkkFC19191D.jpg',
                    'https://mei.animebytes.tv/rC3tm8qlDWk.jpg',
                    'https://mei.animebytes.tv/3Z0pXkjkZbi.jpg',
                    'https://mei.animebytes.tv/5aUnqgxjfLb.jpg',
                    'https://mei.animebytes.tv/3B6NXPfB8p1.jpg',
                    'https://mei.animebytes.tv/30tLOmwJ95a.png',
                    'https://mei.animebytes.tv/UH5jUWxvlwe.png',
                    'https://mei.animebytes.tv/wnXIq1E8Aoo.jpg',
                    'https://mei.animebytes.tv/YLnzmaNLEw1.png',
                    'https://mei.animebytes.tv/nD3WSNHVGNG.png',
                    'https://mei.animebytes.tv/FcvaujHZwrw.png',
                    'https://mei.animebytes.tv/8FbMSKC8mnl.jpg',
                    'https://mei.animebytes.tv/jcR0chaLBUK.png',
                    'https://mei.animebytes.tv/yxej6EkSmil.png',
                    'https://mei.animebytes.tv/yBkJFVpxVVc.jpg',
                    'https://mei.animebytes.tv/K3dShUVaCUJ.png',
                    'https://mei.animebytes.tv/bTXJMAch48I.png',
                    'https://mei.animebytes.tv/wkwIOsS1pmp.png',
                    'https://mei.animebytes.tv/bTXJMAch48I.png',
                    'https://mei.animebytes.tv/1Y1qZx5tCyd.jpg',
                    'https://mei.animebytes.tv/4PMwhcqHL8R.jpg',
                    'https://mei.animebytes.tv/RO5vMlvTjAD.jpg',
                    'https://mei.animebytes.tv/qAKq0OBqdpI.jpg',
                    'https://mei.animebytes.tv/U5LuyT3yFj9.jpg',
                    'https://mei.animebytes.tv/rvD4ptomIlK.jpg',
                    'https://mei.animebytes.tv/4faM5ohJpvu.png',
                    'https://mei.animebytes.tv/hrHd9s7gev0.jpg',
                    'https://mei.animebytes.tv/uJBNqybbaMa.jpg',
                    'https://mei.animebytes.tv/N0MOHFVx1Rc.jpg',
                    'https://mei.animebytes.tv/KcEfPgEMWni.jpg',
                    'https://mei.animebytes.tv/Hs8c3GafGbK.jpg',
                    'https://mei.animebytes.tv/TJewRwzcMlq.png',
                    'https://mei.animebytes.tv/t479gi3lp4n.jpg',
                    'https://mei.animebytes.tv/ypSvLwVrKDD.jpg',
                    'https://mei.animebytes.tv/L9RcNbUVX4w.jpg',
                    'https://mei.animebytes.tv/XR4Lctaxoew.jpg',
                    'https://mei.animebytes.tv/oIbyVtidriP.jpg',
                    'https://mei.animebytes.tv/0gMvIU7UjX2.jpg',
                    'https://mei.animebytes.tv/Y2Wiw7OCU1b.jpg',
                    'https://mei.animebytes.tv/ay1LprgRZt7.jpg',
                    'https://mei.animebytes.tv/An1VqKmtYYT.jpg',
                    'https://mei.animebytes.tv/dhr9lv8GjjN.jpg',
                    'https://mei.animebytes.tv/ai5lzRR1X0V.jpg',
                    'https://mei.animebytes.tv/JovjTMtlfhs.jpg',
                    'https://mei.animebytes.tv/sdPUhRMABEr.jpg',
                    'https://mei.animebytes.tv/KurWxGlL7B6.jpg',
                    'https://mei.animebytes.tv/nibkIMg20Ok.png',
                    'https://mei.animebytes.tv/aY7uILpv5zo.png',
                    'https://mei.animebytes.tv/jhXESI1bFFL.png',
                    'https://mei.animebytes.tv/wyhXCVLKAKI.jpg',
                    'https://mei.animebytes.tv/HaFfme8PLB7.jpg',
                    'https://mei.animebytes.tv/U65vVqsuNm0.png',
                    'https://mei.animebytes.tv/MmIZ0hbCR2Q.png',
                    'https://mei.animebytes.tv/hH3ZMB7qTYz.jpg',
                    'https://mei.animebytes.tv/7FtDt4Lg41t.png',
                    'https://mei.animebytes.tv/CkxulxcZgiQ.png',
                    'https://mei.animebytes.tv/2bDAbYX3Lcu.png',
                    'https://mei.animebytes.tv/itDcuskDzQH.jpg',
                    'https://mei.animebytes.tv/wRmsGRUzGQI.jpg',
                    'https://mei.animebytes.tv/5V2pm8Zju3f.jpg',
                    'https://mei.animebytes.tv/YTeUsZAQIaq.jpg',
                    'https://mei.animebytes.tv/ZKSvbkUCc0p.jpg',
                    'https://mei.animebytes.tv/f2yWvsUJVu3.jpg',
                    'https://mei.animebytes.tv/1MGoecpdDCf.jpg',
                    'https://mei.animebytes.tv/6NAta13HWtB.jpg',
                    'https://mei.animebytes.tv/tbLByXdyufb.jpg',
                    'https://mei.animebytes.tv/n1r0GqG54z5.jpg',
                    'https://mei.animebytes.tv/ZgsekaXv0Tx.png',
                    'https://mei.animebytes.tv/u2XDATX67b2.png',
                    'https://mei.animebytes.tv/WWPdrYF88Rp.jpg',
                    'https://mei.animebytes.tv/QMt8kXW2tRZ.png',
                    'https://mei.animebytes.tv/DhLBIs5XZLy.jpg',
                    'https://mei.animebytes.tv/e6qFIIQhXiT.jpg',
                    'https://mei.animebytes.tv/IKUazeo3Y9Q.jpg',
                    'https://mei.animebytes.tv/Tq2txaf6lrh.jpg',
                    'https://mei.animebytes.tv/ErbHoUH9R9i.png',
                    'https://mei.animebytes.tv/E1tcEO73szK.png',
                    'https://mei.animebytes.tv/trGUtYdybI6.png',
                    'https://mei.animebytes.tv/h0TDNVsZLqG.png',
                    'https://mei.animebytes.tv/kPMH3YlL2wV.jpg',
                    'https://mei.animebytes.tv/XI7ksVJzxas.png',
                    'https://mei.animebytes.tv/vM58wC6MrVb.png',
                    'https://mei.animebytes.tv/xPERwl4tIkt.jpg',
                    'https://mei.animebytes.tv/yzMQv3cCP5G.jpg',
                    'https://mei.animebytes.tv/opAdfZUZAk9.jpg',
                    'https://mei.animebytes.tv/Lb7MIsaBRYv.jpg',
                    'https://mei.animebytes.tv/mfTOwvxfil0.png',
                    'https://mei.animebytes.tv/8RXM967JNL4.jpg',
                    'https://mei.animebytes.tv/1LwPmlwZ7Yq.jpg',
                    'https://mei.animebytes.tv/5U0xIbbN97C.jpg',
                    'https://mei.animebytes.tv/t56fIbRNkuz.jpg',
                    'https://mei.animebytes.tv/aGeBk3mvBcI.png',
                    'https://mei.animebytes.tv/q461BIWFZ9C.png',
                    'https://mei.animebytes.tv/00bo4QVsVwi.png',
                    'https://mei.animebytes.tv/jq7EmyIaL9E.png',
                    'https://mei.animebytes.tv/Fy1m4BeeMOk.png',
                    'https://mei.animebytes.tv/IcnJmRGPXI0.jpg',
                    'https://mei.animebytes.tv/FDbqMOyGV0O.jpg',
                    'https://mei.animebytes.tv/sb8oHGaKCwe.jpg',
                    'https://mei.animebytes.tv/4wGIUcTl4qT.jpg',
                    'https://mei.animebytes.tv/rDW91qDWJhZ.jpg',
                    'https://mei.animebytes.tv/J7W2KG952Zl.png',
                    'https://mei.animebytes.tv/EDcUCUGgh7m.png',
                    'https://mei.animebytes.tv/uLiFmm7KIm6.jpg',
                    'https://mei.animebytes.tv/qbCZlHqlgZX.jpg',
                    'https://mei.animebytes.tv/L7Zjv65ltoZ.png',
                    'https://mei.animebytes.tv/PqUSu9TW0VU.jpg',
                    'https://mei.animebytes.tv/r3nNYN752KF.jpg',
                    'https://mei.animebytes.tv/BXqnTZYpVG0.jpg',
                    'https://mei.animebytes.tv/5MSqCYHKDkE.jpg',
                    'https://mei.animebytes.tv/zzAQhVjjfdA.png',
                    'https://mei.animebytes.tv/O5Ef3DEQNvA.jpg',
                    'https://mei.animebytes.tv/1I0jFYU0qB5.jpg',
                    'https://mei.animebytes.tv/BxNf5nMWDL2.jpg',
                    'https://mei.animebytes.tv/pUopONYc0cZ.png',
                    'https://mei.animebytes.tv/LLvZvHbFwNO.png',
                    'https://mei.animebytes.tv/yUJr5gLCbgS.png',
                    'https://mei.animebytes.tv/NO221KQD4SJ.jpg',
                    'https://mei.animebytes.tv/xvzZQz4kne6.jpg',
                    'https://mei.animebytes.tv/i5ZMpeyeEJi.jpg',
                    'https://mei.animebytes.tv/l0xfgtwOEBG.jpg',
                    'https://mei.animebytes.tv/O7kjXDEvYAe.jpg',
                    'https://mei.animebytes.tv/y3QeOHgxkNr.jpg',
                ];

                global $dessLimiter;
                if(!isset($dessLimiter)){
                    $dessLimiter[$originalSender["id"]] = 0;
                }
                if(time() >= $dessLimiter[$originalSender["id"]]){
                    $dessLimiter[$originalSender["id"]] = time() + 298;
                    sendIRCMessage("Dess! " . $dessList[random_int(0, count($dessList) - 1)], $answer);
                }else{
                    sendIRCMessage("Too much dess. Wait another " . ($dessLimiter[$originalSender["id"]] - time()) . " second(s).", $originalSender["user"]);
                }
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_SUPERADMIN,
            "match" => "#^\\.raw[ \t]+([^ \t]+)[ \t]+(.+)$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $socket;
                echo "[RAWOUT] PRIVMSG ".$matches[1]." :".$matches[2]."\n";
                fwrite($socket, "PRIVMSG ".$matches[1]." :".$matches[2]."\r\n");
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_SUPERADMIN,
            "match" => "#^\\.kick[ \t]+([^ \t]+)[ \t]+([^ \t]+)[ \t]+(.*)$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $socket;
                echo "[RAWOUT] KICK ".$matches[1]." ".$matches[2]." :".$matches[3]."\n";
                fwrite($socket, "KICK ".$matches[1]." ".$matches[2]." :".$matches[3]."\r\n");
            },
        ],

        [
            "targets" => [BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_NONE,
            "match" => "#^\\.(oof|yeet)([ \t]+.*|)$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
      			global $insults; 
                global $socket;
      			$insult = trim($insults[mt_rand(0, count($insults) - 2)]);
                echo "[RAWOUT] KICK $answer ".$originalSender["user"]." :".$matches[1].": ".$insult."\n";
                fwrite($socket, "KICK $answer ".$originalSender["user"]." :".$matches[1].": ".$insult."\r\n");
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.related$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                sendIRCMessage("Related random: Based on history.", $answer);
            },
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.(top|rankings?)([ \t]+(.+)|())$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;
                $params = explode(" ", $matches[3]);

                $timePeriod = 3600 * 24 * 7; //A week
                $timeName = "Weekly";
                $link = false;
                if(count($params) > 0 and trim($params[0]) !== ""){
                    foreach($params as $p){
                        if($p === "week" or $p === "weekly"){
                            $timePeriod = 3600 * 24 * 7;
                            $timeName = "Weekly";
                        }else if($p === "day" or $p === "daily"){
                            $timePeriod = 3600 * 24;
                            $timeName = "Daily";
                        }else if($p === "month" or $p === "monthly"){
                            $timePeriod = 3600 * 24 * 31;
                            $timeName = "Monthly";
                        }else if($p === "year" or $p === "yearly"){
                            $timePeriod = 3600 * 24 * 365;
                            $timeName = "Yearly";
                        }else if($p === "player"){
                            $link = true;
                        }
                    }
                }
                $where = "WHERE play_time >= to_timestamp(".(time() - $timePeriod).")";

                $ids = $db->getHistoryKeys($where);

                $counts = [];
                foreach($ids as $id){
                    @$counts[$id]++;
                }

                arsort($counts);

                $c = 0;
                if($link){
                    $url = "https://". SITE_HOSTNAME ."/player/hash/";
                    foreach($counts as $id => $count){
                        $track = $db->getTrackById($id);
                        $url .= substr($track["hash"], 0, 12).",";
                        ++$c;
                        if($c >= 20){
                            break;
                        }
                    }

                    sendIRCMessage(FORMAT_COLOR_YELLOW . FORMAT_UNDERLINE . "Top 20 $timeName" . FORMAT_RESET ." :: " . $url, $answer);
                }else{
                    foreach($counts as $id => $count){
                        $track = $db->getTrackById($id);

                        sendIRCMessage(FORMAT_COLOR_YELLOW . FORMAT_UNDERLINE . "Top $timeName #" . (++$c) . FORMAT_RESET ." :: Played ".number_format($count)." time(s) :: ". formatTrackName($track), $answer);
                        if($c >= MAX_RESULTS){
                            break;
                        }
                    }
                }
            },
        ],


        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.request[ \t]+(.+)$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db, $torrentIndex;

                if (preg_match("/^(http|https):\\/\\/animebytes\\.tv\\/torrent\\/([0-9]+)\\/download\\/(.*)$/", trim($matches[1]), $matches2) > 0) {
                    $matches[3] = "https://animebytes.tv/torrent/" . $matches2[2] . "/group";
                    sendIRCMessage($originalSender["user"] . ": You might have just posted your passkey! Please reset it ASAP on https://animebytes.tv/user.php?action=edit#account", $answer);
                }

                $group = null;
                if(preg_match("/^(http|https):\\/\\/jpopsuki\\.eu\\/(torrents|torrents2)\\.php\\?.*$/", $matches[1]) > 0){
                    $url = parse_url($matches[1]);
                    $entries = null;
                    @parse_str($url["query"], $entries);
                    if(isset($entries["id"])){
                        $group = $torrentIndex->getGroupBySiteId($entries["id"], 2);
                    }else if(isset($entries["torrentid"])){
                        $torrent = $torrentIndex->getTorrentBySiteId($entries["torrentid"], 2);
                        if($torrent !== null){
                            $group = $torrentIndex->getGroupById($torrent["group_id"]);
                        }
                    }
                }else if(preg_match("/^(http|https):\\/\\/animebytes\\.tv\\/(torrents|torrents2)\\.php\\?.*$/", $matches[1]) > 0){
                    $url = parse_url($matches[1]);
                    $entries = null;
                    @parse_str($url["query"], $entries);
                    if(isset($entries["id"])){
                        $group = $torrentIndex->getGroupBySiteId($entries["id"], 1);
                    }else if(isset($entries["torrentid"])){
                        $torrent = $torrentIndex->getTorrentBySiteId($entries["torrentid"], 1);
                        if($torrent !== null){
                            $group = $torrentIndex->getGroupById($torrent["group_id"]);
                        }
                    }
                }else if(preg_match("/^(http|https):\\/\\/animebytes\\.tv\\/torrent\\/([0-9]+)\\/group$/", trim($matches[1]), $matches2) > 0){
                    $torrent = $torrentIndex->getTorrentBySiteId($matches2[2], 1);
                    if($torrent !== null){
                        $group = $torrentIndex->getGroupById($torrent["group_id"]);
                    }
                }

                if($group !== null){
                    if($group["site_id"] == 1){
                        sendIRCMessage("Torrent group AB#" . $group["site_group_id"] . " is already in the radio! To find it, use: .search #abg-" . $group["site_group_id"], $answer);
                    }else if($group["site_id"] == 2){
                        sendIRCMessage("Torrent group JPS#" . $group["site_group_id"] . " is already in the radio! To find it, use: .search #jpsg-" . $group["site_group_id"], $answer);
                    }
                }else{
                    //TODO: fix this so it works on new Docker system
                    $result = sendNginxApiMessage("/api/requestform/?user=".urlencode($originalSender["id"])."&link=" . urlencode($matches[1]));
                    sendIRCMessage($result["message"], $answer);
                }
            },
        ],
        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.(qrand|qfav)([123]?)([ \t]+(.+)|())$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;

                $num = ((int) $matches[2] > 0 and (int) $matches[2] <= 3) ? (int) $matches[2] : 1;
                $results = [];
                if($matches[1] === "qfav"){
                    $query = urlencode("fav:\"".$originalSender["name"]."\" ".(trim($matches[4]) != "" ? " AND (" .  $matches[4] . ")" : ""));
                }else{
                    $query = urlencode($matches[4]);
                }


                for($i = 0; $i < $num; ++$i){
                    $res = sendApiMessage("/api/request/random?q=" . $query, "GET", null, ["Authorization: " . ($originalSender["identified"] ? $db->getUserApiKey($originalSender["name"], BOT_KEYID) : DEFAULT_API_KEY)]);
                    if(is_array($res) and count($res) > 0){
                        $res = $db->getTrackById($res["id"]);
                        $results[] = $res;
                        sendIRCMessage("Queued: " . formatTrackName($res), $answer);
                    }
                }

                if(count($results) === 0){
                    sendIRCMessage("No results.", $answer);
                }
            }
        ],
        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_MOD,
            "match" => "#^\\.(qrand|qfav)([0-9]*)([ \t]+(.+)|())$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;

                $num = ((int) $matches[2] > 0) ? (int) $matches[2] : 1;
                $results = [];
                for($i = 0; $i < $num; ++$i){
                    $res = sendApiMessage("/api/request/random?q=" . urlencode(($matches[1] === "qfav" ? "fav:\"".$originalSender["name"]."\" " : "") .  $matches[4]), "GET", null, ["Authorization: " . ($originalSender["identified"] ? $db->getUserApiKey($originalSender["name"], BOT_KEYID) : DEFAULT_API_KEY)]);
                    if(is_array($res) and count($res) > 0){
                        $res = $db->getTrackById($res["id"]);
                        $results[] = $res;
                        sendIRCMessage("Queued: " . formatTrackName($res), $answer);
                    }
                }

                if(count($results) === 0){
                    sendIRCMessage("No results.", $answer);
                }
            }
        ],
        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_ADMIN,
            "match" => "#^\\.(qcache)([0-9]*)([ \t]+(.+)|())$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;

                $num = ((int) $matches[2] > 0) ? (int) $matches[2] : 1;
                $results = [];
                for($i = 0; $i < $num; ++$i){
                    $res = sendApiMessage("/api/random?q=" . urlencode($matches[4]), "GET", null, ["Authorization: " . ($originalSender["identified"] ? $db->getUserApiKey($originalSender["name"], BOT_KEYID) : DEFAULT_API_KEY)]);
                    if(is_array($res) and count($res) > 0){
                        $res = $db->getTrackById($res["id"]);
                        $newPath = getFileToCachePath(pathinfo($res["path"], PATHINFO_EXTENSION));
                        if($newPath !== null){
                            copy($res["path"], $newPath);
                            $res["path"] = $newPath;
                            $res["source"] = "@cache";
                            $response = sendKawaApiMessage("/queue/tail", "POST", json_encode($res), [
                                "Content-Type: application/json; utf-8"
                            ]);
                            sendApiMessage("/admin/push", "POST", [
                                "type" => "queue",
                                "data" => json_encode(["action" => "add", "queue_id" => $response["queue_id"], "song" => $res], JSON_NUMERIC_CHECK),
                            ], ["Authorization: " . ($originalSender["identified"] ? $db->getUserApiKey($originalSender["name"], BOT_KEYID) : DEFAULT_API_KEY)]);
                            sendIRCMessage("Queued and cached: " . formatTrackName($res), $answer);
                            $results[] = $res;
                        }
                    }
                }

                if(count($results) === 0){
                    sendIRCMessage("No results.", $answer);
                }
            }
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.(associate|register)([ \t]+.*|)$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;

                if($originalSender["auth"] === null){
                    sendIRCMessage(AUTH_ERROR_MESSAGE, $answer);
                    return;
                }

                $username = strtolower(trim($matches[2]));
      			if($username === ""){
                 $username = $originalSender["name"]; 
                }

                if(!($username == "") and strtolower($username) !== BOT_KEYID){
                    $id = $db->getUserId($username);
                    if($id !== null){
                        $u = $db->getUserById($id);
                        if(isset($u["user_metadata"]["identifier"])){
                            sendIRCMessage(removePing($u["user_metadata"]["identifier"]) . " has already associated " . removePing($username), $answer);
                            return;
                        }
                    }

                    $u = $db->getUserByIdentifier($originalSender["id"]);
                    if($u === null){
                        $meta = [
                            "identifier" => $originalSender["id"],
                            "permission" => "PERMISSION_USER",
                        ];
                        $db->createUser($username);
                        $id = $db->getUserId($username);
                        $db->setUserMetadata($id, $meta);
                        $db->setUserPassword($username, base64_encode(random_bytes(24)));
                        $db->generateUserApiKey($username, BOT_KEYID);
                        sendIRCMessage("Associated and secured " . cleanupCodes($username) . " to " . cleanupCodes($originalSender["id"]), $answer);
                        sendIRCMessage("If you want to generate an API key, you can do so here over private message using .genkey <keyName>, ex. .genkey browser@home", $originalSender["user"]);
                        sendIRCMessage("You can generate any number of API keys, each with a different name. New generated keys with the same name as old ones overwrite them.", $originalSender["user"]);
                    }else{
                        sendIRCMessage("You have already associated " . removePing($u["name"]), $answer);
                    }
                }else{
                  sendIRCMessage("Invalid syntax", $answer);
                }
            }
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.(nick)[ \t]+([a-z0-9\\-_\\[\\]\\{\\}\\\\`\\|]+)$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;

                if(!$originalSender["identified"]){
                    sendIRCMessage(AUTH_ERROR_MESSAGE, $answer);
                    return;
                }

                $username = strtolower(trim($matches[2]));

                $u = $originalSender["record"];
                if($u !== null){
                    $otherId = $db->getUserId($username);
                    if($otherId !== null){
                        sendIRCMessage("This nick is already in use.", $answer);
                        return;
                    }
                    $db->setUserNick($u["id"], $username);
                    sendIRCMessage("Your nick has been changed to $username. All places that refer to you have been updated.", $answer);
                }
            }
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_SUPERADMIN,
            "match" => "#^\\.(nick)[ \t]+([^ ]+)[ \t]+([^ ]+)$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;

                if(!$originalSender["identified"]){
                    sendIRCMessage(AUTH_ERROR_MESSAGE, $answer);
                    return;
                }

                $originalUser = $db->getUserId(strtolower(trim($matches[2])));
                if($originalUser === null){
                    sendIRCMessage("This nick does not exist.", $answer);
                    return;
                }
                $username = strtolower(trim($matches[3]));

                $u = $db->getUserById($originalUser);
                if($u !== null){
                    $otherId = $db->getUserId($username);
                    if($otherId !== null){
                        sendIRCMessage("This nick is already in use.", $answer);
                        return;
                    }
                    $db->setUserNick($u["id"], $username);
                    sendIRCMessage($u["name"]."'s has been changed to $username", $answer);
                }
            }
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.(unassociate|unregister)$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;

                if(!$originalSender["identified"]){
                    sendIRCMessage(AUTH_ERROR_MESSAGE, $answer);
                    return;
                }

                $u = $originalSender["record"];
                if($u !== null){
                    foreach($db->getUserApiKeys($u["name"]) as $key){
                        $db->removeUserApiKey($u["name"], $key);
                    }
                    $db->setUserMetadata($u["id"], []);
                    sendIRCMessage("Unnasociated " . cleanupCodes($originalSender["id"]) . " from " . cleanupCodes($u["name"]), $answer);
                }
            }
        ],

        [
            "targets" => [BOT_NICK],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.genkey[ \t]+(.+)#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;

                if(!$originalSender["identified"]){
                    sendIRCMessage(AUTH_ERROR_MESSAGE, $answer);
                    return;
                }

                $u = $originalSender["record"];
                if($u !== null){
                    $uname = $u["name"];
                    $hash = $db->getUserPassword($uname);
                    if($hash === null or $hash === ""){
                        $db->createUser($uname);
                        $db->setUserPassword($uname, base64_encode(random_bytes(24)));
                        $db->generateUserApiKey($uname, BOT_KEYID);
                    }
                    $newKey = $db->generateUserApiKey($uname, $keyName = trim($matches[1]));
                    sendIRCMessage("Generated API key $keyName: ". FORMAT_BOLD . $newKey, $answer);
                    sendIRCMessage("If you ever lose this key, you will have to generate a new one.", $answer);
                }else{
                    sendIRCMessage("You must have .associate'd first to be able to use this command. Check .help for more information.", $answer);
                }
            }
        ],

        [
            "targets" => [BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.genkey[ \t]+(.+)#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                sendIRCMessage("This command only works over private message", $answer);
            }
        ],

        [
            "targets" => [BOT_NICK],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.lskeys?#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;

                if(!$originalSender["identified"]){
                    sendIRCMessage(AUTH_ERROR_MESSAGE, $answer);
                    return;
                }

                $u = $originalSender["record"];
                if($u !== null){
                    $uname = $u["name"];
                    sendIRCMessage("List of API keys for $uname: ". implode(", ", $db->getUserApiKeys($uname)), $answer);
                }else{
                    sendIRCMessage("You must have .associate'd first to be able to use this command. Check .help for more information.", $answer);
                }
            }
        ],

        [
            "targets" => [BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.lskeys?#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                sendIRCMessage("This command only works over private message", $answer);
            }
        ],

        [
            "targets" => [BOT_NICK],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.(rmkey|delkey)[ \t]+(.+)#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;

                if(!$originalSender["identified"]){
                    sendIRCMessage(AUTH_ERROR_MESSAGE, $answer);
                    return;
                }

                $u = $originalSender["record"];
                if($u !== null and strtolower(trim($matches[2])) !== BOT_KEYID){
                    $uname = $u["name"];
                    $db->removeUserApiKey($uname, trim($matches[2]));
                    sendIRCMessage("Deleted API key ".trim($matches[2])." for " . $uname, $answer);
                }else{
                    sendIRCMessage("Could not delete API key ".trim($matches[2]), $answer);
                }
            }
        ],

        [
            "targets" => [BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.(rmkey|delkey)[ \t]+(.+)#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                sendIRCMessage("This command only works over private message", $answer);
            }
        ],
        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.(analyze|report|log|cue|spectrum|spectra|spectrogram|report)([ \t]+(.+)|())$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;
                $np = null;

                if($matches[3] === ""){
                    $np = $db->getNowPlaying();
                }else{
                    $np = $db->getTrackByHash($matches[3]);
                    if($np === null){
                        $results = sendApiMessage("/api/search?limit=5000&orderBy=score&orderDirection=desc&q=" . urlencode($matches[3]), "GET", null, ["Authorization: " . DEFAULT_API_KEY]);
                        if(count($results) > 1){
                            sendIRCMessage("Found too many results, picking first one.", $answer);
                        }
                        if(count($results) > 0){
                            $np = array_shift($results);
                        }
                    }
                }
                if(isset($np["hash"])){
                    sendIRCMessage(formatTrackName($np), $answer);
                    sendIRCMessage("Path " . FORMAT_ITALIC . str_replace([
                            DATA_MOUNT_PATH,
                        ], "", $np["path"]) . FORMAT_RESET . " :: Audio hash ".$np["audio_hash"]." :: Download https://". SITE_HOSTNAME ."/api/download/" .substr($np["hash"], 0, 12) . " :: Other links will be valid for 60 minutes.", $answer);
                    $files = [];
                    $files["Spectrogram"] = getSpectrogram($np["path"]);
                    foreach(scandir(dirname($np["path"])) as $f){
                        if(preg_match("/\\.(log|cue)$/i", $f, $matches) > 0){
                            $p = getFileToSharePath($matches[1]);
                            if($p !== null){
                                $files[$f] = $p;
                                copy(dirname($np["path"]) . "/" . $f, $p);
                            }
                        }else if(preg_match("/\\.(accurip)$/i", $f, $matches) > 0){
                            $p = getFileToSharePath("log");
                            if($p !== null){
                                $files[str_ireplace($matches[1], "log", $f)] = $p;
                                copy(dirname($np["path"]) . "/" . $f, $p);
                            }
                        }
                    }

                    foreach($files as $f => $path){
                        if($path === null){
                            continue;
                        }
                        $url = "https://". SITE_HOSTNAME . $path;
                        if(preg_match("/\\.(log|cue)$/i", $f, $matches) > 0){
                            if(strtoupper($matches[1]) === "CUE"){
                                sendIRCMessage(strtoupper($matches[1]) . " :: " . FORMAT_ITALIC . $f . FORMAT_RESET . " " . $url, $answer);
                            }
                        }else{
                            sendIRCMessage($f . " " . $url, $answer);
                        }
                    }

                    foreach($files as $f => $path){
                        if($path === null){
                            continue;
                        }
                        $url = "https://". SITE_HOSTNAME . $path;
                        if(preg_match("/\\.(log|cue)$/i", $f, $matches) > 0){
                            if(strtoupper($matches[1]) === "LOG"){
                                $logchecker = new OrpheusNET\Logchecker\Logchecker();
                                $logchecker->newFile($path);
                                $logchecker->parse();

                                $crcMatch = "";
                                if(isset($np["song_metadata"]["audio_crc"])){
                                    if(stripos($logchecker->getLog(), $np["song_metadata"]["audio_crc"]) !== false){
                                        $crcMatch .= " Track "  . FORMAT_BOLD ."CRC " . FORMAT_COLOR_LIGHT_GREEN . strtoupper($np["song_metadata"]["audio_crc"]) . FORMAT_RESET . " match :: ";
                                    }
                                }
                                sendIRCMessage(strtoupper($matches[1]) . " :: " . FORMAT_ITALIC . $f . FORMAT_RESET . " ::$crcMatch Log score " . FORMAT_BOLD . $logchecker->getScore() . "%" . FORMAT_RESET . " :: Ripped using " . FORMAT_ITALIC . $logchecker->getRipper() . " " . $logchecker->getRipperVersion() . FORMAT_RESET . " :: $url", $answer);
                                foreach (array_chunk($logchecker->getDetails(), 4) as $entries){
                                    sendIRCMessage("LOG :: " . implode(" :: ", $entries), $answer);
                                }
                            }
                        }
                    }
                }else{
                    sendIRCMessage("Could not find track", $answer);
                }
            }
        ],
        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.(path|player|get|dl|download|link)([ \t]+(.+)|())$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;
                $np = null;
                if($matches[3] === ""){
                    $np = $db->getNowPlaying();
                }else{
                    $np = $db->getTrackByHash($matches[3]);
                    if($np === null){
                        $results = sendApiMessage("/api/search?limit=5000&orderBy=score&orderDirection=desc&q=" . urlencode($matches[3]), "GET", null, ["Authorization: " . DEFAULT_API_KEY]);
                        if(count($results) > 1){
                            sendIRCMessage("Found too many results, picking first one.", $answer);
                        }
                        if(count($results) > 0){
                            $np = array_shift($results);
                        }
                    }
                }
                if(isset($np["hash"])){
                    sendIRCMessage(formatTrackName($np), $answer);
                    sendIRCMessage("Path " . FORMAT_ITALIC . str_replace([
                            DATA_MOUNT_PATH,
                        ], "", $np["path"]) . FORMAT_RESET . " :: Audio hash ".$np["audio_hash"]." :: Player https://". SITE_HOSTNAME ."/player/hash/" .substr($np["hash"], 0, 12) . " :: DL https://". SITE_HOSTNAME ."/api/download/" .substr($np["hash"], 0, 12), $answer);
                }else{
                    sendIRCMessage("Could not find track", $answer);
                }
            }
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.(artist)([ \t]+(.+)|())$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db, $torrentIndex;
                $np = null;
                if($matches[3] === ""){
                    $np = $db->getNowPlaying();
                }else{
                    $np = $db->getTrackByHash($matches[3]);
                    if($np === null){
                        $results = sendApiMessage("/api/search?limit=5000&orderBy=score&orderDirection=desc&q=" . urlencode($matches[3]), "GET", null, ["Authorization: " . DEFAULT_API_KEY]);
                        if(count($results) > 1){
                            sendIRCMessage("Found too many results, picking first one.", $answer);
                        }
                        if(count($results) > 0){
                            $np = array_shift($results);
                        }
                    }
                }
                if(isset($np["tags"])){
                    $groupId = null;
                    $torrentId = null;
                    $siteType = null;
                    $series = [];
                    $group = null;
                    $artists = [];

                    foreach($np["tags"] as $tag){
                        if(preg_match("/^(ab|jps|red|bbt)t\\-([0-9]+)$/", $tag, $matches) > 0){
                            $torrentId = $matches[2];
                            $siteType = $matches[1];
                        }else if(preg_match("/^(ab|jps|red|bbt)a\\-([0-9]+)$/", $tag, $matches) > 0){
                            $artistId = $matches[2];
                            $siteType = $matches[1];
                            $artists[] = [
                                "id" => $artistId,
                                "site" => $siteType,
                            ];
                        }else if(preg_match("/^(ab|jps|red|bbt)g\\-([0-9]+)$/", $tag, $matches) > 0){
                            $groupId = $matches[2];
                            $siteType = $matches[1];
                            if($siteType === "ab"){
                                $group = $torrentIndex->getGroupBySiteId($groupId, 1);
                            }else if($siteType === "jps"){
                                $group = $torrentIndex->getGroupBySiteId($groupId, 2);
                            }
                        }else if(preg_match("/^(ab|jps|red|bbt)s\\-([0-9]+)$/", $tag, $matches) > 0){
                            $series[] = "#".$matches[1] . "-" . $matches[2];
                        }else if(!(preg_match("/^(ab|jps|red|bbt)[tgsa]\\-([0-9]+)$/", $tag, $matches) > 0)){

                        }
                    }

                    $link = "https://".SITE_HOSTNAME."/player/search/".urlencode("artist=\"".addslashes($np["artist"])."\"");
                    $artistName = $np["artist"];

                    if($siteType !== null and $group !== null){
                        foreach($group["metadata"]["artists"] as $artist){
                            if($artist["type"] === "base"){
                                $link = "https://".SITE_HOSTNAME."/player/".$siteType."a/".$artist["id"];
                                $artistName = $artist["name"];
                                if(
                                    $artist["id"] == 945 or //ZUN
                                    $artist["id"] == 32 or //Various Artists
                                    $artist["id"] == 81 or //Hatsune Miku
                                    $artist["id"] == 1335 or //Megurine Luka
                                    $artist["id"] == 1732 or //Kagamine Rin
                                    $artist["id"] == 3051 or //Kagamine Len
                                    $artist["id"] == 3232 or //GUMI
                                    $artist["id"] == 2684 //EXIT TUNES
                                ){

                                }else{
                                    break;
                                }
                            }
                        }
                    }

                    sendIRCMessage("Artist " . FORMAT_BOLD . $artistName . FORMAT_RESET . " :: Player " . $link, $answer);
                }else{
                    sendIRCMessage("Could not find track", $answer);
                }
            }
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.(album)([ \t]+(.+)|())$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;
                $np = null;
                if($matches[3] === ""){
                    $np = $db->getNowPlaying();
                }else{
                    $np = $db->getTrackByHash($matches[3]);
                    if($np === null){
                        $results = sendApiMessage("/api/search?limit=5000&orderBy=score&orderDirection=desc&q=" . urlencode($matches[3]), "GET", null, ["Authorization: " . DEFAULT_API_KEY]);
                        if(count($results) > 1){
                            sendIRCMessage("Found too many results, picking first one.", $answer);
                        }
                        if(count($results) > 0){
                            $np = array_shift($results);
                        }
                    }
                }
                if(isset($np["tags"])){
                    $groupId = null;
                    $torrentId = null;
                    $siteType = null;
                    $catalog = null;
                    $series = [];
                    $group = null;

                    foreach($np["tags"] as $tag){
                        if(preg_match("/^(ab|jps|red|bbt)t\\-([0-9]+)$/", $tag, $matches) > 0){
                            $torrentId = $matches[2];
                            $siteType = $matches[1];
                        }else if(preg_match("/^(ab|jps|red|bbt)g\\-([0-9]+)$/", $tag, $matches) > 0){
                            $groupId = $matches[2];
                            $siteType = $matches[1];
                        }else if(preg_match("/^(ab|jps|red|bbt)s\\-([0-9]+)$/", $tag, $matches) > 0){
                            $series[] = "#".$matches[1] . "-" . $matches[2];
                        }else if(preg_match("/^catalog\\-(.+)$/iu", $tag, $matches) > 0){
                            $catalog = strtoupper($matches[1]);
                        }else if(!(preg_match("/^(ab|jps|red|bbt)[tgsa]\\-([0-9]+)$/", $tag, $matches) > 0)){

                        }
                    }

                    $link = "https://".SITE_HOSTNAME."/player/album/".urlencode($np["album"]);

                    if($siteType !== null and $torrentId !== null and $groupId !== null){
                        $link = "https://".SITE_HOSTNAME."/player/".$siteType."t/".$torrentId . "/".$siteType."g/".$groupId;
                    }else if($catalog !== null){
                        $link = "https://".SITE_HOSTNAME."/player/catalog/". strtoupper($catalog);
                    }

                    sendIRCMessage("Album " . FORMAT_BOLD . $np["album"] . FORMAT_RESET . " by " . FORMAT_BOLD . $np["artist"] . FORMAT_RESET . ($siteType !== null ? " :: Tags " . FORMAT_ITALIC . "#".$siteType."t-".$torrentId . " " . "#".$siteType."g-".$groupId . FORMAT_RESET : ""). ($catalog !== null ? " :: Catalog# " . strtoupper($catalog) : "") . " :: Player " . $link, $answer);
                }else{
                    sendIRCMessage("Could not find track", $answer);
                }
            }
        ],
        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.(fav|favorite|like|❤️)([ \t]+(.+)|())$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;

                if(!$originalSender["identified"]){
                    sendIRCMessage(AUTH_ERROR_MESSAGE, $answer);
                    return;
                }

                if($matches[3] === ""){
                    $np = $db->getNowPlaying();
                }else{
                    $np = $db->getTrackByHash($matches[3]);
                }
                if(isset($np["hash"])){
                  	if(in_array($originalSender["name"], $np["favored_by"], true)){
                      	global $insults;
						sendIRCMessage(trim($insults[mt_rand(0, count($insults) - 2)]) . " You already enjoyed and favorited this!", $answer);
                    }
                    $l = sendApiMessage("/api/favorites/".$originalSender["name"]."/" . $np["hash"], "PUT", null, ["Authorization: " . $db->getUserApiKey($originalSender["name"], BOT_KEYID)]);
                    $np = $db->getTrackById($np["id"]);
                    sendIRCMessage("Favorited for ".removePing(cleanupCodes($originalSender["name"])).": " . formatTrackName($np), $answer);
                }else{
                    sendIRCMessage("Could not find track", $answer);
                }
            }
        ],

        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_USER,
            "match" => "#^\\.(unfav|unfavorite|unlike|hate)([ \t]+(.+)|())$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                global $db;

                if(!$originalSender["identified"]){
                    sendIRCMessage(AUTH_ERROR_MESSAGE, $answer);
                    return;
                }

                if($matches[3] === ""){
                    $np = $db->getNowPlaying();
                }else{
                    $np = $db->getTrackByHash($matches[3]);
                }
                if(isset($np["hash"])){
                  	if(!in_array($originalSender["name"], $np["favored_by"], true)){
                      	global $insults;
						sendIRCMessage(trim($insults[mt_rand(0, count($insults) - 2)]) . " You did not even like this before!", $answer);
                    }
                    $l = sendApiMessage("/api/favorites/".$originalSender["name"]."/" . $np["hash"], "DELETE", null, ["Authorization: " . $db->getUserApiKey($originalSender["name"], BOT_KEYID)]);
                    $np = $db->getTrackById($np["id"]);
                    sendIRCMessage("Unfavorited for ".removePing(cleanupCodes($originalSender["name"])).": " . formatTrackName($np), $answer);
                }else{
                    sendIRCMessage("Could not find track", $answer);
                }
            }
        ],
        [
            "targets" => [BOT_NICK, BOT_RADIO_CHANNEL],
            "permission" => PERMISSION_NONE,
            "match" => "#^\\.+([^ \t\\.]+)([ \t]+(.+)|())$#iu",
            "command" => function($originalSender, $answer, $to, $matches){
                sendIRCMessage("Unknown, wrong syntax or unauthorized command \"." . $matches[1]. "\", check .help for more information.", $answer);
            }
        ],
    ];

    if($to === BOT_NICK){
        $answer = $sender;
    }else{
        $answer = $to;
    }


    foreach($commands as $cmd){
        if($currentPermissions >= $cmd["permission"] and in_array(strtolower($to), $cmd["targets"], true) and preg_match($cmd["match"], $message, $matches) > 0){
            $cmd["command"]($originalSender, $answer, $to, $matches);
            break;
        }
    }

}

function sortSongs($a, $b){
    $countA = count($a["favored_by"]) * 5 + $a["play_count"] + (substr(strtolower($a["path"]), strrpos(strtolower($a["path"]), '.') + 1) === "flac" ? 5 : 0);
    $countB = count($b["favored_by"]) * 5 + $b["play_count"] + (substr(strtolower($b["path"]), strrpos(strtolower($b["path"]), '.') + 1) === "flac" ? 5 : 0);
    if($countA === $countB){
        return 0;
    }

    return $countB - $countA;
}

function getString($str, $start, $end){
    $str = strstr($str, $start, false);
    return substr($str, strlen($start), strpos($str, $end) - strlen($start));
}

function getYoutubeMetadata($ytId){
    $opts = [
        "http" => [
            "header" => "Accept-Language: en-US,en;q=1.0\r\n"
        ]
    ];
    $context = stream_context_create($opts);
    $content = file_get_contents("https://www.youtube.com/watch?v=" . $ytId, false, $context);
    $meta = null;
    if(preg_match('# ytInitialPlayerResponse = (.*?);[$i<]#m', $content, $matches) > 0){
        $json = json_decode($matches[1]);
        $videoTitle = $json->videoDetails->title;
        $shortDescription = $json->videoDetails->shortDescription;

        $cleanedTitle = preg_replace("#「[^」]+?」#u", '', $videoTitle);
        $cleanedTitle = preg_replace("#【[^」]+?】#u", '', $cleanedTitle);
        $cleanedTitle = preg_replace("#[\\(\\[].*?[\\)\\]]#u", '', $cleanedTitle);
        $cleanedTitle = explode("-", $cleanedTitle);
        $cleanedTitle = trim(array_pop($cleanedTitle));

        $meta = [
            "title" => $cleanedTitle,
            "album" => "[Unknown Album]",
            "artist" => "[Unknown Artist]",
            "artist_raw" => "[Unknown Artist]",
            "album_artist" => "[Unknown Artist]",
            "album_artist_raw" => "[Unknown Artist]",
            "duration" => (int) $json->videoDetails->lengthSeconds
        ];

        foreach (explode("\n", $shortDescription) as $line) {
            $line = trim($line);

            if(preg_match('#(album title|album|収録CD|収録)[ \\t]*[\\:：][ \\t]*(.+)#iu', $line, $matches) > 0){
                $cleanedMatch = preg_replace("#「[^」]+?」#u", '', $matches[2]);
                $cleanedMatch = preg_replace("#【[^」]+?】#u", '', $cleanedMatch);
                $cleanedMatch = preg_replace("#[\\(\\[].*?[\\)\\]]#u", '', $cleanedMatch);
                $meta["album"] = trim($cleanedMatch);
                $meta["album_raw"] = $matches[2];
            }else if(preg_match('#(.*)(artist)[ \\t]*[\\:：][ \\t]*(.+)#iu', $line, $matches) > 0){
                if(mb_stripos($matches[1], "original") !== false or mb_stripos($matches[1], "picture") !== false or mb_stripos($matches[1], "illustration") !== false or mb_stripos($matches[1], "animation") !== false or mb_stripos($matches[1], "movie") !== false){
                    continue;
                }
                $cleanedMatch = preg_replace("#「[^」]+?」#u", '', $matches[3]);
                $cleanedMatch = preg_replace("#【[^」]+?】#u", '', $cleanedMatch);
                $cleanedMatch = preg_replace("#[\\(\\[].*?[\\)\\]]#u", '', $cleanedMatch);
                $meta["artist"] = trim($cleanedMatch);
                $meta["artist_raw"] = $matches[2];
            }else if(preg_match('#(vocals?|ボーカル)[ \\t]*[\\:：][ \\t]*(.+)#iu', $line, $matches) > 0){
                $cleanedMatch = preg_replace("#「[^」]+?」#u", '', $matches[2]);
                $cleanedMatch = preg_replace("#【[^」]+?】#u", '', $cleanedMatch);
                $cleanedMatch = preg_replace("#[\\(\\[].*?[\\)\\]]#u", '', $cleanedMatch);
                if($meta["artist"] === "[Unknown Artist]"){
                    $meta["artist"] = trim($cleanedMatch);
                    $meta["artist_raw"] = $matches[2];
                }
            }else if(preg_match('#(arranger|arranged|arrange|arrangement|編曲)[ \\t]*[\\:：][ \\t]*(.+)#iu', $line, $matches) > 0){
                $cleanedMatch = preg_replace("#「[^」]+?」#u", '', $matches[2]);
                $cleanedMatch = preg_replace("#【[^」]+?】#u", '', $cleanedMatch);
                $cleanedMatch = preg_replace("#[\\(\\[].*?[\\)\\]]#u", '', $cleanedMatch);
                if($meta["artist"] === "[Unknown Artist]"){
                    $meta["artist"] = trim($cleanedMatch);
                    $meta["artist_raw"] = $matches[2];
                }
            }else if(preg_match('#(.*)(composer|composed)[ \\t]*[\\:：][ \\t]*(.+)#iu', $line, $matches) > 0){
                if(mb_stripos($matches[1], "original") !== false){
                    continue;
                }
                $cleanedMatch = preg_replace("#「[^」]+?」#u", '', $matches[3]);
                $cleanedMatch = preg_replace("#【[^」]+?】#u", '', $cleanedMatch);
                $cleanedMatch = preg_replace("#[\\(\\[].*?[\\)\\]]#u", '', $cleanedMatch);
                if($meta["artist"] === "[Unknown Artist]"){
                    $meta["artist"] = trim($cleanedMatch);
                    $meta["artist_raw"] = $matches[2];
                }
            }else if(preg_match('#(publisher|published by|circle|サークル|music by)[ \\t]*[\\:：][ \\t]*(.+)#iu', $line, $matches) > 0){
                $cleanedMatch = preg_replace("#「[^」]+?」#u", '', $matches[2]);
                $cleanedMatch = preg_replace("#【[^」]+?】#u", '', $cleanedMatch);
                $cleanedMatch = preg_replace("#[\\(\\[].*?[\\)\\]]#u", '', $cleanedMatch);
                if($meta["album_artist"] === "[Unknown Artist]"){
                    $meta["album_artist"] = trim($cleanedMatch);
                    $meta["album_artist_raw"] = $matches[2];
                }
            }else if(preg_match('#(.*)(track title|title|曲名|曲|名)[ \\t]*[\\:：][ \\t]*(.+)#iu', $line, $matches) > 0){
                if(mb_stripos($matches[1], "original") !== false or mb_stripos($matches[1], "author") !== false or mb_stripos($matches[1], "原") !== false or mb_stripos($matches[1], "作") !== false){
                    continue;
                }
                $cleanedMatch = preg_replace("#「[^」]+?」#u", '', $matches[3]);
                $cleanedMatch = preg_replace("#【[^」]+?】#u", '', $cleanedMatch);
                $cleanedMatch = preg_replace("#[\\(\\[].*?[\\)\\]]#u", '', $cleanedMatch);
                $meta["title"] = trim($cleanedMatch);
                $meta["title_raw"] = $matches[3];
            }
        }
    }
    if(preg_match('# ytInitialData = (.*);[$i<]#m', $content, $matches) > 0){
        $json = json_decode($matches[1], true);
        foreach($json["contents"]["twoColumnWatchNextResults"]["results"]["results"]["contents"] ?? [] as $e){
            if(isset($e["videoSecondaryInfoRenderer"]) and isset($e["videoSecondaryInfoRenderer"]["metadataRowContainer"])){
                foreach ($e["videoSecondaryInfoRenderer"]["metadataRowContainer"]["metadataRowContainerRenderer"]["rows"] as $r){
                    $row = $r["metadataRowRenderer"] ?? null;
                    if($row !== null){
                        if($row["title"]["simpleText"] === "Artist"){
                            $entry = $row["contents"][0]["simpleText"] ?? $row["contents"][0]["runs"][0]["text"];
                            $cleanedMatch = preg_replace("#「[^」]+?」#u", '', $entry);
                            $cleanedMatch = preg_replace("#【[^」]+?】#u", '', $cleanedMatch);
                            $cleanedMatch = preg_replace("#[\\(\\[].*?[\\)\\]]#u", '', $cleanedMatch);
                            $meta["artist"] = $cleanedMatch;
                            $meta["artist_raw"] = $entry;
                        }else if($row["title"]["simpleText"] === "Album"){
                            $entry = $row["contents"][0]["simpleText"] ?? $row["contents"][0]["runs"][0]["text"];
                            $cleanedMatch = preg_replace("#「[^」]+?」#u", '', $entry);
                            $cleanedMatch = preg_replace("#【[^」]+?】#u", '', $cleanedMatch);
                            $cleanedMatch = preg_replace("#[\\(\\[].*?[\\)\\]]#u", '', $cleanedMatch);
                            $meta["album"] = $cleanedMatch;
                            $meta["album_raw"] = $entry;
                        }else if($row["title"]["simpleText"] === "Song"){
                            $entry = $row["contents"][0]["simpleText"] ?? $row["contents"][0]["runs"][0]["text"];
                            $cleanedMatch = preg_replace("#「[^」]+?」#u", '', $entry);
                            $cleanedMatch = preg_replace("#【[^」]+?】#u", '', $cleanedMatch);
                            $cleanedMatch = preg_replace("#[\\(\\[].*?[\\)\\]]#u", '', $cleanedMatch);
                            $meta["title"] = $cleanedMatch;
                            $meta["title_raw"] = $entry;
                        }
                    }
                }
            }
        }
    }


    return $meta;
}

function getMediaMetadata($url){
    ob_start();
    passthru("ffprobe -v quiet -icy 1 -print_format json -show_streams -show_format " . escapeshellarg($url));
    $data = json_decode(ob_get_contents(), true);
    ob_end_clean();
    foreach($data["streams"] as $stream){
        if($stream["codec_type"] === "audio" and (isset($stream["tags"]["title"]) or isset($stream["tags"]["TITLE"]))){
            return [
                "title" => $stream["tags"]["TITLE"] ?? ($stream["tags"]["title"] ?? ""),
                "album" => $stream["tags"]["ALBUM"] ?? ($stream["tags"]["album"] ?? "[Unknown Album]"),
                "artist" => $stream["tags"]["album_artist"] ?? ($stream["tags"]["ARTIST"] ?? ($stream["tags"]["artist"] ?? "[Unknown Artist]")),
                "artist_raw" => $stream["tags"]["ARTIST"] ?? ($stream["tags"]["artist"] ?? "[Unknown Artist]"),
                "album_artist_raw" => $stream["tags"]["album_artist"] ?? "[Unknown Artist]",
                "duration" => floor($stream["duration"]),
            ];
        }
    }

    if(isset($data["format"]["tags"]) and (isset($data["format"]["tags"]["title"]) or isset($data["format"]["tags"]["TITLE"]))){
        return [
            "title" => $data["format"]["tags"]["TITLE"] ?? ($data["format"]["tags"]["title"] ?? ""),
            "album" => $data["format"]["tags"]["ALBUM"] ?? ($data["format"]["tags"]["album"] ?? "[Unknown Album]"),
            "artist" => $data["format"]["tags"]["album_artist"] ?? ($data["format"]["tags"]["ARTIST"] ?? ($data["format"]["tags"]["artist"] ?? "[Unknown Artist]")),
            "artist_raw" => $data["format"]["tags"]["ARTIST"] ?? ($data["format"]["tags"]["artist"] ?? "[Unknown Artist]"),
            "album_artist_raw" => $data["format"]["tags"]["album_artist"] ?? "[Unknown Artist]",
            "duration" => floor($data["format"]["duration"]),
        ];
    }

    return null;
}

function getFileToCachePath($extension){
    $extension = strtolower($extension);
    if($extension === "flac" or $extension === "mp3" or $extension === "m4a" or $extension === "mp4" or $extension === "aac" or $extension === "ogg" or $extension === "opus"){
        $fileName = hash("sha256", random_bytes(32)) . "." . $extension;
        return "/cache/" . $fileName;
    }

    return null;
}

function getFileToSharePath($extension){
    $extension = strtolower($extension);
    if($extension === "jpg" or $extension === "png" or $extension === "cue" or $extension === "log"){
        $fileName = hash("sha256", random_bytes(32)) . "." . $extension;
        return "/shares/" . $fileName;
    }

    return null;
}

function getSpectrogram($path){
    $output = getFileToSharePath("png");
    if($output === null){
        return null;
    }
    ob_start();
    passthru("sox -q " . escapeshellarg($path) ." -n remix 1 spectrogram -y 1025 -x 3000 -z 120 -w Kaiser -t " . escapeshellarg(basename($path)). " -o " . escapeshellarg($output));
    ob_end_clean();

    if(@filesize($output) > 1000){
        return $output;
    }

    return null;
}

function sendKawaApiMessage($url, $method = "GET", $postData = null, array $headers = []){
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL,"http://" . HOST_KAWA . $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $msg = curl_exec($ch);

    curl_close ($ch);
    $json = @json_decode($msg, true);
    return is_array($json) ? $json : $msg;
}

function sendApiMessage($url, $method = "GET", $postData = null, array $headers = []){
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL,"http://" . HOST_API . $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    //curl_setopt($ch, CURLOPT_VERBOSE, 1);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $msg = curl_exec($ch);

    curl_close($ch);
    $json = @json_decode($msg, true);
    return is_array($json) ? $json : $msg;
}

function sendNginxApiMessage($url, $method = "GET", $postData = null, array $headers = []){
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL,"http://" . HOST_NGINX . $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $msg = str_replace(["\r", "\n", "\\r", "\\n"], "", curl_exec($ch));

    curl_close($ch);
    $json = @json_decode($msg, true);
    return is_array($json) ? $json : $msg;
}

function randomCase($str){
    for($i = 0; $i < strlen($str); ++$i){
        $str[$i] = (mt_rand(0, 1) == 0 ? strtoupper($str[$i]) : strtolower($str[$i]));
    }
    return $str;
}

$context = stream_context_create([
    "socket" => [
        //"bindto" => "0:0",
        "bindto" => "[::]:0",
    ],
    "ssl" => [
        "peer_name" => IRC_SERVER_HOST,
        "verify_peer" => true,
        "verify_peer_name" => true,
        "allow_self_signed" => false,
    ],
]);

$socket = stream_socket_client("tls://".IRC_SERVER_HOST.":" . IRC_SERVER_PORT, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
//socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);
//socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
if($socket === false or !is_resource($socket)/* or !socket_connect($socket, $host, 6661)*/){
    echo("[ERROR] IRCChat can't be started: $errno : ".$errstr . PHP_EOL);
    return;
}
//socket_getpeername($socket, $addr, $port);
//socket_set_nonblock($socket);

$extraAuth = [];
$lastResult = [];
$lastResultIndex = 0;

$db = new Database(DB_MUSIC_CONNSTRING);
$torrentIndex = new TorrentIndex(DB_TORRENTS_CONNSTRING);
define("TEMP_NICK", BOT_USE_SATSUKI ? BOT_NICK : BOT_NICK . "_" . random_int(0, 40));
$client = new IRCClient($socket, TEMP_NICK, IRC_SERVER_PASS, [
    "PRIVMSG NickServ :RECOVER ".BOT_NICK." " . BOT_PASSWORD,
    "",
    "PRIVMSG NickServ :RELEASE ".BOT_NICK." " . BOT_PASSWORD,
    "",
    "NICK " . BOT_NICK,
    "",
    "PRIVMSG NickServ :IDENTIFY " . BOT_PASSWORD,
    "",
    "JOIN " . BOT_RADIO_CHANNEL . "," . BOT_NP_CHANNEL,
    "",
]);

$insults = file(".quotes");
$client->run();
