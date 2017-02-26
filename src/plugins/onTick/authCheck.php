<?php
/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2016 Robert Sardinia
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */


/**
 * Class fileAuthCheck
 * @property int nextCheck
 */
class authCheck
{

    /**
     * @var
     */
    private $config;
    private $guildID;
    private $corpTickers;
    private $authGroups;
    private $exempt;
    private $alertChannel;
    private $guild;
    private $nameEnforce;
    private $standingsBased;
    private $standings;
    private $nameCheck;
    private $apiKey;
    private $discord;
    private $logger;

    /**
     * @param $config
     * @param $discord
     * @param $logger
     */
    public function init($config, $primary, $discord, $logger)
    {
        $this->config = $config;
        $this->discord = $discord;
        $this->logger = $logger;
        $this->guildID = $config['bot']['guild'];
        $this->exempt = $config['plugins']['auth']['exempt'];
        $this->corpTickers = $config['plugins']['auth']['corpTickers'];
        $this->nameEnforce = $config['plugins']['auth']['nameEnforce'];
        $this->standingsBased = $config['plugins']['auth']['standings']['enabled'];
        $this->standings = $config['plugins']['auth']['standings'];
        $this->apiKey = $config['eve']['apiKeys'];
        $this->authGroups = $config['plugins']['auth']['authGroups'];
        $this->alertChannel = (int)$config['plugins']['auth']['alertChannel'];
        $this->guild = $config['bot']['guild'];
        $this->nextCheck = 0;

        //Set name check to happen if corpTicker or nameEnforce is set
        if ($this->nameEnforce === 'true' || $this->corpTickers === 'true') {
            $this->nameCheck = 'true';
        }

        //check if cache has been set
        $permsChecked = getPermCache('permsLastChecked');
        $namesChecked = getPermCache('nextRename');
        if ($namesChecked === NULL) {
            setPermCache('nextRename', time());
        }

        //if not set set for now (30 minutes from now for role removal)
        if ($permsChecked === NULL) {
            setPermCache('permsLastChecked', time() - 5);
            setPermCache('authStateLastChecked', time() + 7200);
        }
    }

    public function tick()
    {
        // What was the servers last reported state
        $lastStatus = getPermCache('serverState');
        if ($lastStatus === 'online') {
            $permsChecked = getPermCache('permsLastChecked');
            $stateChecked = getPermCache('authStateLastChecked');
            $namesChecked = getPermCache('nextRename');
            $standingsChecked = getPermCache('nextStandingsCheck');

            if ($permsChecked <= time()) {
                $this->logger->addInfo('AuthCheck: Checking for users who have left corp/alliance....');
                $this->checkPermissions();
                $this->logger->addInfo('AuthCheck: Corp/alliance check complete.');
            }

            if ($stateChecked <= time()) {
                $this->logger->addInfo('AuthCheck: Checking for users who have been wrongly given roles....');
                $this->checkAuthState();
                $this->logger->addInfo('AuthCheck: Role check complete.');
            }

            if ($this->nameCheck === 'true' && $namesChecked <= time()) {
                $this->logger->addInfo('AuthCheck: Resetting player names....');
                $this->nameReset();
                $this->logger->addInfo('AuthCheck: Names reset.');
            }

            if ($this->standingsBased === 'true' && $standingsChecked <= time()) {
                $this->logger->addInfo('AuthCheck: Updating Standings');
                $this->standingsUpdate();
                $this->logger->addInfo('AuthCheck: Standings Updated');
            }
        }
    }

    /**
     * @return null
     */

    //Remove members who have roles but never authed
    private function checkPermissions()
    {
        //Get guild object
        $guild = $this->discord->guilds->get('id', $this->guildID);

        //get auth users
        $result = getAuthUsers();

        //Set empty arrays
        $corpArray = array();
        $allianceArray = array();

        // If config is outdated
        if (null === $this->authGroups) {
            $msg = '**Auth Failure:** Please update the bots config to the latest version.';
            queueMessage($msg, $this->alertChannel, $this->guild);
            $nextCheck = time() + 10800;
            setPermCache('permsLastChecked', $nextCheck);
            return null;
        }

        //Set corp/ally id arrays
        foreach ($this->authGroups as $authGroup) {
            if ($authGroup['corpID'] !== 0) {
                $corpArray[] = (int)$authGroup['corpID'];
            }
            if ($authGroup['allianceID'] !== 0) {
                $allianceArray[] = (int)$authGroup['allianceID'];
            }
        }

        if (count($result) >= 1) {
            foreach ($result as $rows) {
                $charID = $rows['characterID'];
                $discordID = $rows['discordID'];
                $role = $rows['role'];
                $member = @$guild->members->get('id', $discordID);
                $roles = $member->roles;

                //get member roles
                $roleArray = array();
                foreach ($roles as $role) {
                    $roleArray[] = (string)$role->name;
                }

                //Fix corrupt role
                if (strpos($role, '<@&') !== false) {
                    fixRole($discordID, 'fallback');
                    continue;
                }

                $member = $guild->members->get('id', $discordID);
                //Check if member has roles
                if (null === @$member->roles) {
                    continue;
                }

                //Auth things
                $character = characterDetails($charID);

                //Postpone check if ESI is down to prevent timeouts
                if (@$character['error'] === 'The datasource tranquility is temporarily unavailable') {
                    $this->logger->addInfo('AuthCheck: The datasource tranquility is temporarily unavailable, check canceled.');
                    $nextCheck = time() + 10800;
                    setPermCache('permsLastChecked', $nextCheck);
                    return null;
                }


                //if issue with esi, skip
                $timeout = 0;
                while (null === @$character['corporation_id']) { //try 10 times to pull characterDetails
                    if ($timeout > 3) {
                        continue;
                    } else {
                        $character = characterDetails($charID);
                        $timeout++;
                    }
                }

                $corporationID = $character['corporation_id'];
                $corporationDetails = corpDetails($corporationID);
                if (null === $corporationDetails) {
                    continue;
                }
                $allianceID = @$corporationDetails['alliance_id'];

                //check if user authed based on standings
                $standings = null;
                $allianceContacts = getContacts($allianceID);
                $corpContacts = getContacts($corporationID);
                if (in_array($this->standings['plus10Role'], $roleArray) && ((int)$allianceContacts['standing'] === 10 || (int)$corpContacts['standing'] === 10)) {
                    $standings = 1;
                }
                if (in_array($this->standings['plus5Role'], $roleArray) && ((int)$allianceContacts['standing'] === 5 || (int)$corpContacts['standing'] === 5)) {
                    $standings = 1;
                }
                if (in_array($this->standings['neutralRole'], $roleArray) && (((int)$allianceContacts['standing'] === 0 || (int)$corpContacts['standing'] === 0) || ((int)$allianceContacts['standing'] === null || (int)$corpContacts['standing'] === null))) {
                    $standings = 1;
                }
                if (in_array($this->standings['minus10Role'], $roleArray) && ((int)$allianceContacts['standing'] === -10 || (int)$corpContacts['standing'] === -10)) {
                    $standings = 1;
                }
                if (in_array($this->standings['minus5Role'], $roleArray) && ((int)$allianceContacts['standing'] === -5 || (int)$corpContacts['standing'] === -5)) {
                    $standings = 1;
                }

                //check corp and alliance
                if (!in_array((int)$allianceID, $allianceArray) && !in_array((int)$corporationID, $corpArray) && null === $standings) {
                    // Deactivate user in database
                    disableUser($discordID);
                    continue;
                }
            }
            $nextCheck = time() + 10800;
            setPermCache('permsLastChecked', $nextCheck);
            return null;
        }
        $nextCheck = time() + 10800;
        setPermCache('permsLastChecked', $nextCheck);
        return null;
    }

    private function checkAuthState()
    {

        //Check if exempt roles are set
        if (null === $this->exempt) {
            $this->exempt = '0';
        }

        // If config is outdated
        if (null === $this->authGroups) {
            $msg = '**Auth Failure:** Please update the bots config to the latest version.';
            queueMessage($msg, $this->alertChannel, $this->guild);
            //queue up next check
            $nextCheck = time() + 1800;
            setPermCache('authStateLastChecked', $nextCheck);
            return null;
        }

        //get bot ID so we don't remove out own roles
        $botID = $this->discord->id;

        //Get guild object
        $guild = $this->discord->guilds->get('id', $this->guildID);

        //Check to make sure guildID is set correctly
        if (null === $guild) {
            $this->logger->addError('Config Error: Ensure the guild entry in the config is the guildID (aka serverID) for the main server that the bot is in.');
            $nextCheck = time() + 7200;
            setPermCache('authLastChecked', $nextCheck);
            return null;
        }

        //create empty array to store names
        $removedRoles = array();
        $userCount = 0;

        //Perform check if roles were added without permission
        foreach ($guild->members as $member) {
            $id = $member->id;
            $username = $member->username;
            $roles = $member->roles;

            //Skip to next member if this user has no roles
            if (null === $roles) {
                continue;
            }
            $result = getAuthUser($id);

            //If they are NOT active in the db, check for roles to remove
            if (null === $result) {
                $userCount++;
                foreach ($roles as $role) {
                    if ($id !== $botID && !in_array($role->name, $this->exempt, true)) {
                        $member->removeRole($role);
                        $guild->members->save($member);
                        // Add users name to array
                        if (!in_array($username, $removedRoles)) {
                            $removedRoles[] = $username;
                        }
                    }
                }
            }
        }
        //Report removed users to log and channel
        $nameList = implode(', ', $removedRoles);
        if ($userCount > 0 && strlen($nameList) > 3 && null !== $nameList) {
            $msg = "Following users roles have been removed - {$nameList}";
            queueMessage($msg, $this->alertChannel, $this->guild);
            $this->logger->addInfo("AuthCheck: Roles removed from {$nameList}");
        }
        //queue up next check
        $nextCheck = time() + 1800;
        setPermCache('authStateLastChecked', $nextCheck);
        return null;
    }

    private function nameReset()
    {
        //Get guild object
        $guild = $this->discord->guilds->get('id', $this->guildID);

        $result = getAuthUsers();

        // If config is outdated
        if (null === $this->authGroups) {
            $msg = '**Auth Failure:** Please update the bots config to the latest version.';
            queueMessage($msg, $this->alertChannel, $this->guild);
            $nextCheck = time() + 1800;
            setPermCache('nextRename', $nextCheck);
            return null;
        }

        if (count($result) >= 1) {
            foreach ($result as $rows) {
                $charID = $rows['characterID'];
                $discordID = $rows['discordID'];
                $member = $guild->members->get('id', $discordID);
                $eveName = $rows['eveName'];
                //Check if member has roles
                if (null === @$member->roles) {
                    continue;
                }

                //Get current nickname
                $guild = $this->discord->guilds->get('id', $this->guildID);
                $member = $guild->members->get('id', $discordID);
                $nickName = $member->nick;
                $userName = $member->user->username;
                //If nick isn't set than make it username
                if ($nickName === '' || null === $nickName) {
                    $nickName = $userName;
                }

                //Check for bad tickers
                if (strpos($nickName, '[U]') !== false) {
                    $nickName = str_replace('[U]', '', $nickName);
                    queueRename($discordID, $nickName, $this->guildID);
                    continue;
                }

                //corp ticker
                if ($this->corpTickers === 'true') {
                    $character = characterDetails($charID);
                    if (null === $character['corporation_id']) {
                        continue;
                    }
                    $corpInfo = getCorpInfo($character['corporation_id']);
                    //Clean bad entries
                    if (@$corpInfo['corpTicker'] === 'U') {
                        deleteCorpInfo(@$corpInfo['corpID']);
                    }
                    $nick = null;
                    if (null !== @$corpInfo['corpTicker']) {
                        $corpTicker = (string)$corpInfo['corpTicker'];
                        if ($this->nameEnforce === 'true') {
                            $nick = "[{$corpTicker}] {$eveName}";
                        } elseif ((string)$nickName === "[{$corpTicker}]") {
                            $nick = "[{$corpTicker}] {$userName}";
                        } elseif (strpos($nickName, $corpTicker) === false) {
                            $nick = "[{$corpTicker}] {$nickName}";
                        } elseif (strpos($nickName, $corpTicker) !== false) {
                            continue;
                        }
                        if ($nick !== $nickName) {
                            queueRename($discordID, $nick, $this->guildID);
                        }
                        continue;
                    }
                    $corporationDetails = corpDetails($character['corporation_id']);
                    if (null === $corporationDetails) {
                        continue;
                    }
                    $corpTicker = $corporationDetails['ticker'];
                    //Check for bad tickers (ESI ERROR?)
                    if (@$corpTicker === 'U') {
                        continue;
                    }
                    $corpName = (string)$corporationDetails['corporation_name'];
                    if (null !== $corpTicker) {
                        if ($this->nameEnforce === 'true') {
                            $nick = "[{$corpTicker}] {$eveName}";
                        } elseif ((string)$nickName === "[{$corpTicker}]") {
                            $nick = "[{$corpTicker}] {$userName}";
                        } elseif (strpos($nickName, $corpTicker) === false) {
                            $nick = "[{$corpTicker}] {$nickName}";
                        } elseif (strpos($nickName, $corpTicker) !== false) {
                            continue;
                        }
                        if ($nick !== $nickName) {
                            queueRename($discordID, $nick, $this->guildID);
                            addCorpInfo($character['corporation_id'], $corpTicker, $corpName);
                        }
                        continue;
                    }
                    continue;
                }
                $nick = "{$eveName}";
                if ($nick !== $nickName) {
                    queueRename($discordID, $nick, $this->guildID);
                }
                continue;
            }
            $nextCheck = time() + 1800;
            setPermCache('nextRename', $nextCheck);
            return null;
        }
        $nextCheck = time() + 1800;
        setPermCache('nextRename', $nextCheck);
        return null;

    }

    private function standingsUpdate()
    {
        foreach ($this->apiKey as $apiKey) {
            if ((string)$apiKey['keyID'] === (string)$this->config['plugins']['auth']['standings']['apiKey']) {
                $url = "https://api.eveonline.com/char/ContactList.xml.aspx?keyID={$apiKey['keyID']}&vCode={$apiKey['vCode']}&characterID={$apiKey['characterID']}";
                $xml = makeApiRequest($url);
                if (empty($xml)) {
                    return null;
                }
                foreach ($xml->result->rowset as $contactType) {
                    if ((string)$contactType->attributes()->name === 'corporateContactList' || 'allianceContactList') {
                        foreach ($contactType->row as $contact) {
                            if (null !== $contact['contactID'] && $contact['contactName'] && $contact['standing']) {
                                addContactInfo($contact['contactID'], $contact['contactName'], $contact['standing']);
                            }
                        }
                    }
                }
            }
        }
        $nextCheck = time() + 86400;
        setPermCache('nextStandingsCheck', $nextCheck);
    }
}
