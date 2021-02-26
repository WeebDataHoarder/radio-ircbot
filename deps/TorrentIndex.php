<?php


class TorrentIndex{
    /*

    */
    //SITE_ID = animebytes.tv: 1, jpopsuki.eu: 2
    private $dbconn;
    public function __construct($connstr){
        if(!($this->dbconn = pg_connect($connstr))){
            exit();
        }
    }

    public function getStats(){
        $query = 'SELECT (SELECT COUNT(*) FROM groups WHERE site_id = 1) as ab_groups, (SELECT COUNT(*) FROM groups WHERE metadata = \'[]\' AND site_id = 1) as ab_empty_groups, (SELECT COUNT(*) FROM groups WHERE site_id = 2) as jps_groups, (SELECT COUNT(*) FROM groups WHERE metadata = \'[]\' AND site_id = 2) as jps_empty_groups, (SELECT COUNT(*) FROM torrents WHERE site_id = 1) as ab_torrents, (SELECT COUNT(*) FROM torrents WHERE site_id = 2) as jps_torrents;';
        $result = pg_query($this->dbconn, $query);
        return pg_fetch_array($result, null, PGSQL_ASSOC);
    }

    public function matchFile($filePath, $size, array $basePaths = []){
        $fname = basename($filePath);
        $pname = str_replace($basePaths, "", $filePath);

        $results = [

        ];

        $result = pg_query_params($this->dbconn, 'SELECT * FROM files WHERE fpath = $1 AND size = $2;', [$pname, $size]);
        if(pg_num_rows($result) > 0){
            if($row = pg_fetch_array($result, null, PGSQL_ASSOC)) {
                $row["matchQuality"] = 1;
                $row["matchType"] = "path_size";
                $results[] = $row;
            }

            return $results;
        }

        $result = pg_query_params($this->dbconn, 'SELECT * FROM files WHERE fname = $1 AND size = $2;', [$fname, $size]);
        if(pg_num_rows($result) > 0){
            if($row = pg_fetch_array($result, null, PGSQL_ASSOC)) {
                $row["metadata"] = json_decode($row["metadata"], true);
                $row["matchQuality"] = 2;
                $row["matchType"] = "file_size";
                $results[] = $row;
            }

            return $results;
        }

        $result = pg_query_params($this->dbconn, 'SELECT * FROM files WHERE fpath = $1;', [$pname]);
        if(pg_num_rows($result) > 0){
            if($row = pg_fetch_array($result, null, PGSQL_ASSOC)) {
                $row["metadata"] = json_decode($row["metadata"], true);
                $row["matchQuality"] = 3;
                $row["matchType"] = "path";
                $results[] = $row;
            }
        }

        $result = pg_query_params($this->dbconn, 'SELECT * FROM files WHERE fname = $1;', [$fname]);
        if(pg_num_rows($result) > 0){
            if($row = pg_fetch_array($result, null, PGSQL_ASSOC)) {
                $row["metadata"] = json_decode($row["metadata"], true);
                $row["matchQuality"] = 3;
                $row["matchType"] = "file";
                $results[] = $row;
            }
        }

        $result = pg_query_params($this->dbconn, 'SELECT * FROM files WHERE size = $1;', [$size]);
        if(pg_num_rows($result) > 0){
            if($row = pg_fetch_array($result, null, PGSQL_ASSOC)) {
                $row["metadata"] = json_decode($row["metadata"], true);
                $row["matchQuality"] = 4;
                $row["matchType"] = "size";
                $results[] = $row;
            }
        }

        return $results;
    }

    public function updateGroup($groupId, array $metadata){
        pg_query_params($this->dbconn, 'UPDATE groups SET metadata = $1 WHERE id = $2;', [json_encode($metadata), $groupId]);
    }

    public function getGroupBySiteId($groupId, $siteId){
        $result = pg_query_params($this->dbconn, 'SELECT * FROM groups WHERE site_group_id = $1 AND site_id = $2;', [$groupId, $siteId]);
        if($row = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            $row["metadata"] = json_decode($row["metadata"], true);
            return $row;
        }
        return null;
    }

    public function getTorrentBySiteId($torrentId, $siteId){
        $result = pg_query_params($this->dbconn, 'SELECT * FROM torrents WHERE site_torrent_id = $1 AND site_id = $2;', [$torrentId, $siteId]);
        if($row = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            $row["metadata"] = json_decode($row["metadata"], true);
            return $row;
        }
        return null;
    }

    public function getTorrentById($torrentId){
        $result = pg_query_params($this->dbconn, 'SELECT * FROM torrents WHERE id = $1;', [$torrentId]);
        if($row = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            $row["metadata"] = json_decode($row["metadata"], true);
            return $row;
        }
        return null;
    }

    public function getGroupById($groupId){
        $result = pg_query_params($this->dbconn, 'SELECT * FROM groups WHERE id = $1;', [$groupId]);
        if($row = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            $row["metadata"] = json_decode($row["metadata"], true);
            return $row;
        }
        return null;
    }

    public function getTorrentByHash($hash, $siteId = null){
        if($siteId !== null){
            $result = pg_query_params($this->dbconn, 'SELECT * FROM torrents WHERE hash = $1 AND site_id = $2;', [$hash, $siteId]);
        }else{
            $result = pg_query_params($this->dbconn, 'SELECT * FROM torrents WHERE hash = $1;', [$hash]);
        }
        if($row = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            $row["metadata"] = json_decode($row["metadata"], true);
            return $row;
        }
        return null;
    }

    public function insertGroup($siteGroupId, $siteId, array $metadata = []){
        $res = pg_query_params($this->dbconn, 'INSERT INTO groups (site_id, site_group_id, metadata) VALUES ($1, $2, $3) RETURNING id;', [$siteId, $siteGroupId, json_encode($metadata)]);
        return is_resource($res) ? pg_fetch_row($res)[0] : null;
    }

    public function insertTorrent($hash, $groupId, $siteId, $siteTorrentId, $siteGroupId, array $files, array $metadata = []){
        $res = pg_query_params($this->dbconn, 'INSERT INTO torrents (group_id, hash, site_id, site_torrent_id, site_group_id, metadata) VALUES ($1, $2, $3, $4, $5, $6) RETURNING id;', [$groupId, $hash, $siteId, $siteTorrentId, $siteGroupId, json_encode($metadata)]);
        $torrentId = is_resource($res) ? pg_fetch_row($res)[0] : null;
        if($torrentId === null){
            return null;
        }
        foreach($files as $f => $size){
            pg_query_params($this->dbconn, 'INSERT INTO files (parent_id, fname, fpath, size) VALUES ($1, $2, $3, $4) RETURNING id;', [$torrentId, basename($f), $f, $size]);
        }

        return $torrentId;
    }

}
