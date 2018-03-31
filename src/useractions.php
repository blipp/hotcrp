<?php
// useractions.php -- HotCRP helpers for user actions
// Copyright (c) 2008-2018 Eddie Kohler; see LICENSE.

class UserActions {
    const PWTYPE_NO = 0;
    const PWTYPE_YES = 1;
    const PWTYPE_LOCAL = 2;

    static private function modify_password_mail($where, $pwtype, $sendtype, $ids) {
        global $Conf;
        $j = (object) array("ok" => true);
        $result = Dbl::qe("select * from ContactInfo where $where and contactId ?a", $ids);
        while (($Acct = Contact::fetch($result, $Conf))) {
            if ($pwtype === self::PWTYPE_YES
                || ($pwtype === self::PWTYPE_LOCAL
                    && (!($cdbu = $Acct->contactdb_user())
                        || $cdbu->password !== ""))) {
                $Acct->change_password(null, null, Contact::CHANGE_PASSWORD_NO_CDB);
            }
            if ($sendtype && !$Acct->disabled) {
                $Acct->sendAccountInfo($sendtype, false);
            } else if ($sendtype) {
                $j->warnings[] = "Not sending mail to disabled account " . htmlspecialchars($Acct->email) . ".";
            }
        }
        return $j;
    }

    static function disable($ids, $contact) {
        global $Conf;
        $old_nerrors = Dbl::$nerrors;
        $enabled_cids = Dbl::fetch_first_columns("select contactId from ContactInfo where contactId ?a and disabled=0 and contactId!=?", $ids, $contact->contactId);
        if ($enabled_cids)
            Dbl::qe("update ContactInfo set disabled=1 where contactId ?a", $enabled_cids);
        if (Dbl::$nerrors > $old_nerrors)
            return (object) ["error" => true];
        else if (!count($enabled_cids))
            return (object) ["ok" => true, "warnings" => ["Those accounts were already disabled."]];
        else {
            $Conf->save_logs(true);
            foreach ($enabled_cids as $cid)
                $Conf->log_for($contact, $cid, "Account disabled");
            $Conf->save_logs(false);
            return (object) ["ok" => true];
        }
    }

    static function enable($ids, $contact) {
        global $Conf;
        $old_nerrors = Dbl::$nerrors;
        Dbl::qe("update ContactInfo set disabled=1 where contactId ?a and password='' and contactId!=?", $ids, $contact->contactId);
        $disabled_cids = Dbl::fetch_first_columns("select contactId from ContactInfo where contactId ?a and disabled=1 and contactId!=?", $ids, $contact->contactId);
        if ($disabled_cids)
            Dbl::qe("update ContactInfo set disabled=0 where contactId ?a", $disabled_cids);
        if (Dbl::$nerrors > $old_nerrors)
            return (object) ["error" => true];
        else if (empty($disabled_cids))
            return (object) ["ok" => true, "warnings" => ["Those accounts were already enabled."]];
        else {
            $Conf->save_logs(true);
            foreach ($disabled_cids as $cid)
                $Conf->log_for($contact, $cid, "Account enabled");
            $Conf->save_logs(false);
            return self::modify_password_mail("password='' and contactId!=" . $contact->contactId, self::PWTYPE_LOCAL, "create", $disabled_cids);
        }
    }

    static function reset_password($ids, $contact) {
        return self::modify_password_mail("contactId!=" . $contact->contactId, self::PWTYPE_YES, false, $ids);
    }

    static function send_account_info($ids, $contact) {
        return self::modify_password_mail("true", self::PWTYPE_NO, "send", $ids);
    }
}
