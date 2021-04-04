<?php


class Database{
    public $dbconn;
    public function __construct($connstr){
        if(!($this->dbconn = pg_connect($connstr))){
            exit();
        }
    }

    public function getTracks($where = "", $extra = ""){
        $sql = <<<SQL
SELECT
    songs.id AS id,
    songs.hash AS hash,
    songs.title AS title,
    (SELECT artists.name FROM artists WHERE songs.artist = artists.id LIMIT 1) AS artist,
    (SELECT albums.name FROM albums WHERE songs.album = albums.id LIMIT 1) AS album,  
    songs.path AS path,
    songs.duration AS duration,
    songs.status AS status,
    songs.cover AS cover,
    songs.lyrics AS lyrics,
    songs.play_count AS play_count,
    songs.audio_hash AS audio_hash,
    songs.song_metadata AS song_metadata,
    array_to_json(ARRAY(SELECT tags.name FROM tags JOIN taggings ON (taggings.tag = tags.id) WHERE taggings.song = songs.id)) AS tags,
    array_to_json(ARRAY(SELECT users.name FROM users JOIN favorites ON (favorites.user_id = users.id) WHERE favorites.song = songs.id)) AS favored_by
FROM songs
$where
$extra
;
SQL;
        $result = pg_query($this->dbconn, $sql);
        $rows = [];
        while($data = pg_fetch_array($result, null, PGSQL_ASSOC)){
            foreach($data as $k=>&$v){
                if($k === "tags" or $k === "favored_by" or $k === "song_metadata" or $k === "lyrics"){
                    $v = json_decode($v, true);
                }
            }

            $rows[] = $data;
        }

        return $rows;
    }

    public function getNowPlaying(){
        return $this->getHistory(1)[0];
    }

    public function getHistoryKeys($where = ""){
        $query = 'SELECT song FROM history '.$where.' ORDER BY play_time DESC;';
        $result = pg_query($this->dbconn, $query);
        $history = [];

        //There could be repeated values, do it like this for now.
        while ($row = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            $history[] = (int) $row["song"];
        }

        return $history;
    }

    public function getHistory($limit = 100){
        $query = 'SELECT EXTRACT(EPOCH FROM play_time) as play_time,song,source FROM history ORDER BY play_time DESC LIMIT '.intval($limit).';';
        $result = pg_query($this->dbconn, $query);
        $history = [];

        //There could be repeated values, do it like this for now.
        while ($row = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            $res = $this->getTrackById($row["song"]);
            $res["source"] = $row["source"];
            $res["started"] = $row["play_time"];
            $history[] = $res;
        }

        return $history;
    }

    public function getStats(){
        $query = 'SELECT (SELECT COUNT(*) FROM songs) as total_count, (SELECT COUNT(*) FROM favorites) as total_favorites, (SELECT COUNT(*) FROM artists) as total_artists, (SELECT COUNT(*) FROM albums) as total_albums, (SELECT COUNT(*) FROM history) as total_plays, (SELECT SUM(duration) FROM songs WHERE duration > 0 AND duration < 100000) as total_duration;';
        $result = pg_query($this->dbconn, $query);
        return pg_fetch_array($result, null, PGSQL_ASSOC);
    }

    public function getArtistById($id){
        $query = 'SELECT * FROM artists WHERE id = '.intval($id).';';
        $result = pg_query($this->dbconn, $query);
        if($row = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            return $row;
        }
        return null;
    }

    public function getAlbumById($id){
        $query = 'SELECT * FROM albums WHERE id = '.intval($id).';';
        $result = pg_query($this->dbconn, $query);
        if($row = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            return $row;
        }
        return null;
    }

    public function createTag($tag){
        $res = pg_query_params($this->dbconn, 'INSERT INTO tags (name) VALUES ($1) RETURNING id;', [$tag]);
        return is_resource($res) ? pg_fetch_row($res)[0] : null;
    }

    public function getTagIdByName($name){
        $query = 'SELECT * FROM tags WHERE name ILIKE $1;';
        $result = pg_query_params($this->dbconn, $query, [$name]);
        if($row = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            return $row["id"];
        }
        return null;
    }

    public function removeTagging($songId, $tagId){
        pg_query_params($this->dbconn, 'DELETE FROM taggings WHERE song = $1 AND tag = $2;', [$songId, $tagId]);
    }

    public function addTagging($songId, $tagId){
        pg_query_params($this->dbconn, 'INSERT INTO taggings (song, tag) VALUES ($1, $2) ON CONFLICT DO NOTHING;', [$songId, $tagId]);
    }

    public function getTrackById($id){
        $results = $this->getTracks("WHERE songs.status = 'active' AND songs.id = ".intval($id)."");
        return count($results) > 0 ? $results[0] : null;
    }

    public function getTrackByHash($hash){
        if(preg_match("/^[a-f0-9]{32}$/iu", $hash) > 0){
            $hash = strtolower($hash);
            $results = $this->getTracks("WHERE songs.status = 'active' AND songs.hash = '{$hash}'");
            return count($results) > 0 ? $results[0] : null;
        }else  if(preg_match("/^[a-f0-9]{6,}$/iu", $hash) > 0){
            $hash = strtolower($hash);
            $results = $this->getTracks("WHERE songs.status = 'active' AND songs.hash LIKE '{$hash}%'");
            return count($results) > 0 ? $results[0] : null;
        }

        return null;
    }

    public function setTrackLyrics($id, $type, $content){
        $res = pg_query_params($this->dbconn, 'SELECT lyrics FROM songs WHERE id = $1;', $p = [$id]);
        while (($row = pg_fetch_assoc($res)) !== false){
            $lyrics = (object) json_decode($row["lyrics"]);
            $lyrics->{$type} = $content;
            pg_query_params($this->dbconn, 'UPDATE songs SET lyrics = $2 WHERE id = $1;', [$id, json_encode($lyrics)]);
        }
    }

    public function getUserId($user) {
        $query = 'SELECT id FROM users WHERE name ILIKE $1;';
        $result = pg_query_params($this->dbconn, $query, [$user]);
        if($row = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            return $row["id"];
        }
        return null;
    }

    public function setUserNick($id, $name) {
        $query = 'UPDATE users SET name = $2 WHERE id = $1;';
        pg_query_params($this->dbconn, $query, [$id, $name]);
    }

    public function getUserById($id) {
        $query = 'SELECT id, name, user_metadata FROM users WHERE id = $1;';
        $result = pg_query_params($this->dbconn, $query, [$id]);
        if($row = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            $row["user_metadata"] = @(array) json_decode($row["user_metadata"], true);
            return $row;
        }
        return null;
    }

    public function getUserByIdentifier($identifier) {
        $query = 'SELECT id, name, user_metadata FROM users WHERE user_metadata->>\'identifier\' = $1;';
        $result = pg_query_params($this->dbconn, $query, [$identifier]);
        if($row = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            $row["user_metadata"] = @(array) json_decode($row["user_metadata"], true);
            return $row;
        }
        return null;
    }

    public function setUserMetadata($id, array $metadata) {
        $query = 'UPDATE users SET user_metadata = $2 WHERE id = $1;';
        pg_query_params($this->dbconn, $query, [$id, json_encode($metadata)]);
    }

    public function getUserPassword($user) {
        if($this->getUserId($user) === null){
            return null;
        }
        $query = 'SELECT password FROM users WHERE name ILIKE $1;';
        $result = pg_query_params($this->dbconn, $query, [$user]);
        if($row = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            return $row["password"];
        }
        return "";
    }

    public function checkUserPassword($user, $password){
        if($this->getUserId($user) === null){
            return false;
        }
        $query = 'SELECT password FROM users WHERE name ILIKE $1;';
        $result = pg_query_params($this->dbconn, $query, [$user]);
        $hash = "";
        if($row = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            $hash = $row["password"];
        }
        if($hash === ""){ //No password set
            return true;
        }
        return password_verify($password, $hash);
    }

    public function setUserPassword($user, $password) {
        if($this->getUserId($user) === null){
            return false;
        }
        $newHash = password_hash($password, PASSWORD_BCRYPT);
        pg_query_params($this->dbconn, 'UPDATE users SET password = $1 WHERE name ILIKE $2;', [$newHash, $user]);
        return true;
    }

    public function createUser($user) {
        if($this->getUserId($user) !== null){
            //User exists
            return false;
        }
        pg_query_params($this->dbconn, 'INSERT INTO users (name) VALUES ($1) ON CONFLICT DO NOTHING;', [strtolower($user)]);
        return true;
    }

    public function getUserApiKey($user, $apiKeyName) {
        if(($id = $this->getUserId($user)) === null){
            return null;
        }
        $query = 'SELECT key FROM user_api_keys WHERE name = $1 AND "user" = $2;';
        $result = pg_query_params($this->dbconn, $query, [$apiKeyName, (int) $id]);
        if($row = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            return $row["key"];
        }
        return null;
    }

    public function generateUserApiKey($user, $apiKeyName) {
        $hash = $this->getUserPassword($user);
        if($hash === null or $hash === ""){
            //No password set
            return false;
        }
        $id = $this->getUserId($user);
        $newKey = str_replace(["=", "/", "+"], ["", "-", "_"], base64_encode(random_bytes(32)));
        pg_query_params($this->dbconn, 'INSERT INTO user_api_keys (name, "user", key) VALUES ($1, $2, $3) ON CONFLICT ON CONSTRAINT user_api_keys_user_pkey DO UPDATE SET key = $3;', [$apiKeyName, (int) $id, $newKey]);
        return $newKey;
    }

    public function getUserApiKeys($user) {
        if(($id = $this->getUserId($user)) === null){
            return [];
        }
        $query = 'SELECT name FROM user_api_keys WHERE "user" = $1;';
        $result = pg_query_params($this->dbconn, $query, [(int) $id]);
        $results = [];
        while($row = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            $results[] = $row["name"];
        }
        return $results;
    }

    public function removeUserApiKey($user, $apiKeyName) {
        $id = $this->getUserId($user);
        if($id === null){
            return;
        }

        pg_query_params($this->dbconn, 'DELETE FROM user_api_keys WHERE "user" = $1 AND name = $2;', [(int) $id, $apiKeyName]);
    }
}