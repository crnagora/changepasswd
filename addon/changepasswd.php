#!/usr/bin/php
<?php
/*
 * Title: ChangePassword plugin.
 * Version: 1.0.0 (8/Nov/2015)
 * Author: Denis.
 * License: GPL.
 * Site: https://montenegro-it.com
 * Email: contact@montenegro-it.com
 */
@set_time_limit(0);
@error_reporting(E_NONE);
@ini_set('display_errors', 0);

$xml_string = file_get_contents("php://stdin");
$doc = simplexml_load_string($xml_string);
$func = $doc->params->func;
$sok = $doc->params->sok;
$elid = $doc->params->elid;
$user = $doc["user"];
$level = $doc["level"];
define("BILLMGR_CONFIG", "/usr/local/ispmgr/etc/billmgr.conf");
define("PLUGIN_XML", "/usr/local/ispmgr/etc/billmgr_mod_changepasswd.xml");
define("DEFAULT_LANG", "ru");
define("MYSQL_HOST", "localhost");
define("MYSQL_DB", "billmgr");
define("MYSQL_USER", "root");

class ChangePassword {

    static public function clear_billmgrcache($table) {
        exec('/usr/local/ispmgr/sbin/mgrctl -m billmgr drop.cache elid=' . $table);
    }

    static public function connect_db() {
        $handle = fopen(BILLMGR_CONFIG, "r");
        while (!feof($handle)) {
            $line = fgets($handle);
            $search_string = strpos($line, "DBPassword");
            if ($search_string !== false) {
                $dbpassword = trim(substr($line, $search_string + strlen("DBPassword")));
                break;
            }
        }
        fclose($handle);
        if (empty($dbpassword)) {
            return false;
        }
        $mysqli = new mysqli(MYSQL_HOST, MYSQL_USER, $dbpassword, MYSQL_DB);
        return $mysqli;
    }

    static public function update_password($hash, $user_id) {
        $mysqli = self::connect_db();
        $mysqli->query("START TRANSACTION");
        $query = $mysqli->query("UPDATE `user` SET `password`='" . $hash . "', `changepasswd`=NOW() WHERE `id`='" . $user_id . "'");
        if ($query) {
            $mysqli->query("COMMIT");
            self::clear_billmgrcache('user');
        } else {
            $mysqli->query("ROLLBACK");
        }
        $mysqli->close();
    }

    static public function generate_password($max = 8) {
        $array_symbol = array('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm',
            'n', 'o', 'p', 'r', 's', 't', 'u', 'v', 'x', 'y', 'z', 'A', 'B', 'C', 'D',
            'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'R', 'S', 'T',
            'U', 'V', 'X', 'Y', 'Z', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0');
        $password = "";
        for ($i = 0; $i < $max; $i++) {
            $index = rand(0, count($array_symbol) - 1);
            $password .= $array_symbol[$index];
        }
        return $password;
    }

    static public function get_userdata($user_id) {
        $mysqli = self::connect_db();
        $mysqli->set_charset("utf8");
        $result = $mysqli->query("SELECT `id`,`name`,`realname`,`email`,`lang`,`changepasswd`,`account` FROM `user` WHERE `id`='" . $user_id . "'");
        $data = $result->fetch_object();
        $mysqli->close();
        return $data;
    }

    //для billmanager corporate, можно задать руками массив $data
    static public function get_projectdata($account_id) {
        $mysqli = self::connect_db();
        $mysqli->set_charset("utf8");
        $result = $mysqli->query("SELECT t1.project, t2.name,t2.domain,t2.notifyemail FROM account2project AS t1 LEFT JOIN  project AS t2 ON t1.project=t2.id WHERE t1.account='" . $account_id . "' GROUP BY t1.account");
        $data = $result->fetch_object();
        $mysqli->close();
        return $data;
    }

    static public function send_mail($email, $login, $lang = DEFAULT_LANG, $password, $projectname, $projectdomain, $realname, $from) {
        $mail = self::parse_config($lang);
        $tmp_message = $mail['text'];
        $message = self::create_message($tmp_message, $login, $password, $projectname, $projectdomain, $realname);
        $headers = array();
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-type: text/plain; charset=utf-8";
        $headers[] = "From: " . $projectname . " <" . $from . ">";
        $headers[] = "Reply-To: " . $projectname . " <" . $from . ">";
        $headers[] = "Subject: {$mail['subject']}";
        mail($email, $mail['subject'], $message . $email, implode("\r\n", $headers));
    }

    static public function create_message($message, $login, $password, $projectname, $projectdomain, $realname) {
        $parse = array("__LOGIN__" => $login, "__PASSWORD__" => $password, "__PROJECTDOMAIN__" => $projectdomain, "__REALNAME__" => $realname, "__PROJECTNAME__" => $projectname);
        foreach ($parse AS $key => $item) {
            $message = str_replace($key, $item, $message);
        }
        return $message;
    }

    static public function parse_config($lang) {
        $out_message = "";
        $out_subject = "";
        $config = file_get_contents(PLUGIN_XML);
        preg_match_all("|<lang name=\"(.*)\">(.*)<msg name=\"newpassword_message\">(.*)</msg>(.*)<\/lang>|iUs", $config, $out_message, PREG_PATTERN_ORDER);
        preg_match_all("|<lang name=\"(.*)\">(.*)<msg name=\"newpassword_subject\">(.*)</msg>(.*)<\/lang>|iUs", $config, $out_subject, PREG_PATTERN_ORDER);
        $key = array_search($lang, $out_message[1]);
        if (!$key) {
            $key = array_search(DEFAULT_LANG, $out_message[1]);
        }
        $mail['text'] = $out_message[3][$key];
        $mail['subject'] = $out_subject[3][$key];
        return $mail;
    }

}

switch ($func) {
    case "changepasswd.run";
        $mysqli = ChangePassword::connect_db();
        if ($mysqli->connect_errno) {
            $doc->addChild("changepasswd", "SQL ERROR: " . $mysqli->connect_error);
            break;
        }
        if (!$elid[0]) {
            $id = $doc->params->user_id[0];
        } else {
            $id = $elid[0];
        }
        $user_data = ChangePassword::get_userdata($id);
        $project_data = ChangePassword::get_projectdata($user_data->account);
        if ($sok == "ok") {
            if ($user_data->email) {
                ChangePassword::send_mail($user_data->email, $user_data->name, $user_data->lang, $doc->params->user_password[0], $project_data->name, $project_data->domain, $user_data->realname, $project_data->notifyemail);
                ChangePassword::update_password($doc->params->user_hash[0], $id);
            }
            $doc->addChild("ok", "ok");
            break;
        }
        $password = ChangePassword::generate_password();
        $salt = ChangePassword::generate_password();
        $hash = crypt($password, '$1$' . $salt . '$');
        $doc->addChild("changepasswd", "login: " . $user_data->name . "<br />email: " . $user_data->email . "<br />passwd: " . $password . "<input type=\"hidden\" name=\"user_id\"  value=\"" . $elid . "\"><input type=\"hidden\" name=\"user_hash\"  value=\"" . $hash . "\"><input type=\"hidden\" name=\"user_password\"  value=\"" . $password . "\">");
        break;
    case "changepasswd.action";
        if ($sok == "ok") {
            $doc->addChild("ok", "ok");
            break;
        }
        break;
}
echo $doc->asXML();
