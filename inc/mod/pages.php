<?php
/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */
use Vichan\Context;
use Vichan\Functions\Format;
use Vichan\Functions\Net;
use Vichan\Data\Driver\CacheDriver;

defined('TINYBOARD') or exit;


function _link_or_copy(string $target, string $link): bool {
	if (!link($target, $link)) {
		error_log("Failed to link() $target to $link. Falling back to copy()");
		return copy($target, $link);
	}
	return true;
}

function mod_page($title, $template, $args, $mod, $subtitle = false) {
	global $config;

	$options = [
		'config' => $config,
		'mod' => $mod,
		'hide_dashboard_link' => $template == $config['file_mod_dashboard'],
		'title' => $title,
		'subtitle' => $subtitle,
		'boardlist' => createBoardlist($mod),
		'body' => Element(
			$template,
			array_merge(
				[ 'config' => $config, 'mod' => $mod ],
				$args
			)
		)
	];

	if ($mod) {
		$options['pm'] = create_pm_header();
	}

	echo Element($config['file_page_template'], $options);
}

function mod_login(Context $ctx, $redirect = false) {
    global $mod;
    $config = $ctx->get('config');

    $args = [];

    // Check if CAPTCHA is enabled in the config
    if ($config['captcha']['provider'] === 'hcaptcha') {
        $hcaptcha_secret = $config['captcha']['hcaptcha']['secret'];
        $hcaptcha_sitekey = $config['captcha']['hcaptcha']['sitekey'];
        $args['hcaptcha_sitekey'] = $hcaptcha_sitekey;
    } elseif ($config['captcha']['provider'] === 'native') {
        session_start(); // Start session for native CAPTCHA
    }

    if (isset($_POST['login'])) {
        // Check if inputs are set and not empty
        if (!isset($_POST['username'], $_POST['password']) || $_POST['username'] == '' || $_POST['password'] == '') {
            $args['error'] = $config['error']['invalid'];
        }
        // Check hCaptcha (if enabled)
        elseif ($config['captcha']['provider'] === 'hcaptcha' && 
                (!isset($_POST['h-captcha-response']) || !verify_hcaptcha($_POST['h-captcha-response'], $hcaptcha_secret))) {
            $args['error'] = 'Captcha verification failed. Please try again.';
        }
        // Check native CAPTCHA (if enabled)
        elseif ($config['captcha']['provider'] === 'native') {
            if (!isset($_POST['captcha']) || $_POST['captcha'] === '') {
                $args['error'] = $config['error']['captcha'];
            } elseif (!isset($_SESSION['captcha']) || strcasecmp($_SESSION['captcha'], $_POST['captcha']) !== 0) {
                $args['error'] = $config['error']['captcha']; // CAPTCHA failed
            }
        }
        // If username and password are valid, attempt login
        if (!isset($args['error']) && !login($_POST['username'], $_POST['password'])) {
            if ($config['syslog'])
                _syslog(LOG_WARNING, 'Unauthorized login attempt!');

            $args['error'] = $config['error']['invalid'];
        } elseif (!isset($args['error'])) {
            modLog('Logged in');

            // Login successful
            setCookies();

            // Redirect after login
            if ($redirect)
                header('Location: ?' . $redirect, true, $config['redirect_http']);
            else
                header('Location: ?/', true, $config['redirect_http']);
            exit;
        }
    }

    if (isset($_POST['username']))
        $args['username'] = $_POST['username'];

    mod_page(_('Login'), $config['file_mod_login'], $args, $mod);
}


function mod_register(Context $ctx, $redirect = false) {
    global $mod;
    $config = $ctx->get('config');
    $args = [];

    // Check if CAPTCHA is enabled in the config
    if ($config['captcha']['provider'] === 'hcaptcha') {
        $hcaptcha_secret = $config['captcha']['hcaptcha']['secret'];
        $hcaptcha_sitekey = $config['captcha']['hcaptcha']['sitekey'];
        $args['hcaptcha_sitekey'] = $hcaptcha_sitekey;
    } elseif ($config['captcha']['provider'] === 'native') {
        session_start(); // Start session for native CAPTCHA
    }

    if (isset($_POST['register'])) {
        // Check if inputs are set and not empty
        if (!isset($_POST['username'], $_POST['password']) || $_POST['username'] == '' || $_POST['password'] == '') {
            $args['error'] = $config['error']['invalid'];
        }
        // Check hCaptcha (if enabled)
        elseif ($config['captcha']['provider'] === 'hcaptcha' && 
                (!isset($_POST['h-captcha-response']) || !verify_hcaptcha($_POST['h-captcha-response'], $hcaptcha_secret))) {
            $args['error'] = 'Captcha verification failed. Please try again.';
        }
        // Check native CAPTCHA (if enabled)
        elseif ($config['captcha']['provider'] === 'native') {
            if (!isset($_POST['captcha']) || $_POST['captcha'] === '') {
                $args['error'] = $config['error']['captcha'];
            } elseif (!isset($_SESSION['captcha']) || strcasecmp($_SESSION['captcha'], $_POST['captcha']) !== 0) {
                $args['error'] = $config['error']['captcha']; // CAPTCHA failed
            }
        }
        // If username and password are valid, attempt registration
        if (!isset($args['error']) && !register($_POST['username'], $_POST['password'])) {
            if ($config['syslog'])
                _syslog(LOG_WARNING, 'Failed registration attempt!');

            $args['error'] = $config['error']['invalid'];
        } elseif (!isset($args['error'])) {
            modLog('Registered new account');

            // Registration successful
            if ($redirect)
                header('Location: ?' . $redirect, true, $config['redirect_http']);
            else
                header('Location: ?/', true, $config['redirect_http']);
            exit;
        }
    }

    if (isset($_POST['username']))
        $args['username'] = $_POST['username'];

    mod_page(_('Register'), $config['file_mod_register'], $args, $mod);
}

/**
 * Verify hCaptcha response
 */
function verify_hcaptcha($token, $secret) {
    // Prepare data to send to hCaptcha's verification endpoint
    $data = [
        'secret' => $secret,
        'response' => $token
    ];

    // HTTP options for POST request
    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data)
        ]
    ];

    // Make request to hCaptcha's verification endpoint
    $context  = stream_context_create($options);
    $result = file_get_contents('https://hcaptcha.com/siteverify', false, $context);
    if ($result === false) {
        return false;
    }

    // Decode the response
    $resultJson = json_decode($result, true);
    return isset($resultJson['success']) && $resultJson['success'] == true;
}

function mod_confirm(Context $ctx, $request) {
	global $mod;
	$config = $ctx->get('config');
	mod_page(
		_('Confirm action'),
		$config['file_mod_confim'],
		[
			'request' => $request,
			'token' => make_secure_link_token($request)
		],
		$mod
	);
}

function mod_logout(Context $ctx) {
	$config = $ctx->get('config');
	destroyCookies();

	header('Location: ?/', true, $config['redirect_http']);
}

function mod_dashboard(Context $ctx) {
	global $mod;
	$config = $ctx->get('config');

	$args = [];

	$args['boards'] = listBoards();

	if (hasPermission($config['mod']['noticeboard'])) {
		if (!$args['noticeboard'] = $ctx->get(CacheDriver::class)->get('noticeboard_preview')) {
			$query = prepare("SELECT ``noticeboard``.*, `username` FROM ``noticeboard`` LEFT JOIN ``mods`` ON ``mods``.`id` = `mod` ORDER BY `id` DESC LIMIT :limit");
			$query->bindValue(':limit', $config['mod']['noticeboard_dashboard'], PDO::PARAM_INT);
			$query->execute() or error(db_error($query));
			$args['noticeboard'] = $query->fetchAll(PDO::FETCH_ASSOC);

			$ctx->get(CacheDriver::class)->set('noticeboard_preview', $args['noticeboard']);
		}
	}

	if ($args['unread_pms'] = $ctx->get(CacheDriver::class)->get('pm_unreadcount_' . $mod['id']) === false) {
		$query = prepare('SELECT COUNT(*) FROM ``pms`` WHERE `to` = :id AND `unread` = 1');
		$query->bindValue(':id', $mod['id']);
		$query->execute() or error(db_error($query));
		$args['unread_pms'] = $query->fetchColumn();

		$ctx->get(CacheDriver::class)->set('pm_unreadcount_' . $mod['id'], $args['unread_pms']);
	}

	$query = query('SELECT COUNT(*) FROM ``reports``') or error(db_error($query));
	$args['reports'] = $query->fetchColumn();

	$query = query('SELECT COUNT(*) FROM ``ban_appeals``') or error(db_error($query));
	$args['appeals'] = $query->fetchColumn();

	if ($mod['type'] >= ADMIN && $config['check_updates']) {
		if (!$config['version'])
			error(_('Could not find current version! (Check .installed)'));

		if (isset($_COOKIE['update'])) {
			$latest = unserialize($_COOKIE['update']);
		} else {
			$ctx = stream_context_create(array('http' => array('timeout' => 5)));
			if ($code = @file_get_contents('http://engine.vichan.info/version.txt', 0, $ctx)) {
				$ver = strtok($code, "\n");

				if (preg_match('@^// v(\d+)\.(\d+)\.(\d+)\s*?$@', $ver, $matches)) {
					$latest = array(
						'massive' => $matches[1],
						'major' => $matches[2],
						'minor' => $matches[3]
					);
					if (preg_match('/(\d+)\.(\d)\.(\d+)(-dev.+)?$/', $config['version'], $matches)) {
						$current = array(
							'massive' => (int) $matches[1],
							'major' => (int) $matches[2],
							'minor' => (int) $matches[3]
						);
						if (isset($m[4])) {
							// Development versions are always ahead in the versioning numbers
							$current['minor'] --;
						}
						// Check if it's newer
						if (!(	$latest['massive'] > $current['massive'] ||
							$latest['major'] > $current['major'] ||
								($latest['massive'] == $current['massive'] &&
									$latest['major'] == $current['major'] &&
									$latest['minor'] > $current['minor']
								)))
							$latest = false;
					} else {
						$latest = false;
					}
				} else {
					// Couldn't get latest version
					$latest = false;
				}
			} else {
				// Couldn't get latest version
				$latest = false;
			}

			setcookie('update', serialize($latest), time() + $config['check_updates_time'], $config['cookies']['jail'] ? $config['cookies']['path'] : '/', null, !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off', true);
		}

		if ($latest)
			$args['newer_release'] = $latest;
	}

	$args['logout_token'] = make_secure_link_token('logout');

	mod_page(_('Dashboard'), $config['file_mod_dashboard'], $args, $mod);
}

function mod_search_redirect(Context $ctx) {
	$config = $ctx->get('config');

	if (!hasPermission($config['mod']['search']))
		error($config['error']['noaccess']);

	if (isset($_POST['query'], $_POST['type']) && in_array($_POST['type'], array('posts', 'IP_notes', 'bans', 'log'))) {
		$query = $_POST['query'];
		$query = urlencode($query);
		$query = str_replace('_', '%5F', $query);
		$query = str_replace('+', '_', $query);

		if ($query === '') {
			header('Location: ?/', true, $config['redirect_http']);
			return;
		}

		header('Location: ?/search/' . $_POST['type'] . '/' . $query, true, $config['redirect_http']);
	} else {
		header('Location: ?/', true, $config['redirect_http']);
	}
}

function mod_search(Context $ctx, $type, $search_query_escaped, $page_no = 1) {
	global $pdo, $config, $mod;

	if (!hasPermission($config['mod']['search']))
		error($config['error']['noaccess']);

	// Unescape query
	$query = str_replace('_', ' ', $search_query_escaped);
	$query = urldecode($query);
	$search_query = $query;

	// Form a series of LIKE clauses for the query.
	// This gets a little complicated.

	// Escape "escape" character
	$query = str_replace('!', '!!', $query);

	// Escape SQL wildcard
	$query = str_replace('%', '!%', $query);

	// Use asterisk as wildcard instead
	$query = str_replace('*', '%', $query);

	$query = str_replace('`', '!`', $query);

	// Array of phrases to match
	$match = [];

	// Exact phrases ("like this")
	if (preg_match_all('/"(.+?)"/', $query, $exact_phrases)) {
		$exact_phrases = $exact_phrases[1];
		foreach ($exact_phrases as $phrase) {
			$query = str_replace("\"{$phrase}\"", '', $query);
			$match[] = $pdo->quote($phrase);
		}
	}

	// Non-exact phrases (ie. plain keywords)
	$keywords = explode(' ', $query);
	foreach ($keywords as $word) {
		if (empty($word))
			continue;
		$match[] = $pdo->quote($word);
	}

	// Which `field` to search?
	if ($type == 'posts')
		$sql_field = array('body_nomarkup', 'files', 'subject', 'filehash', 'ip', 'name', 'trip');
	if ($type == 'IP_notes')
		$sql_field = 'body';
	if ($type == 'bans')
		$sql_field = 'reason';
	if ($type == 'log')
		$sql_field = 'text';

	// Build the "LIKE 'this' AND LIKE 'that'" etc. part of the SQL query
	$sql_like = '';
	foreach ($match as $phrase) {
		if (!empty($sql_like))
			$sql_like .= ' AND ';
		$phrase = preg_replace('/^\'(.+)\'$/', '\'%$1%\'', $phrase);
		if (is_array($sql_field)) {
			foreach ($sql_field as $field) {
				$sql_like .= '`' . $field . '` LIKE ' . $phrase . ' ESCAPE \'!\' OR';
			}
			$sql_like = preg_replace('/ OR$/', '', $sql_like);
		} else {
			$sql_like .= '`' . $sql_field . '` LIKE ' . $phrase . ' ESCAPE \'!\'';
		}
	}

	// Compile SQL query

	if ($type == 'posts') {
		$query = '';
		$boards = listBoards();
		if (empty($boards))
			error(_('There are no boards to search!'));

		foreach ($boards as $board) {
			openBoard($board['uri']);
			if (!hasPermission($config['mod']['search_posts'], $board['uri']))
				continue;

			if (!empty($query))
				$query .= ' UNION ALL ';
			$query .= "SELECT * FROM ``posts`` WHERE `board` = " . $pdo->quote($board['uri']) . " AND ($sql_like)";
		}

		// You weren't allowed to search any boards
		if (empty($query))
				error($config['error']['noaccess']);

		$query .= ' ORDER BY `sticky` DESC, `id` DESC';
	}

	if ($type == 'IP_notes') {
		$query = 'SELECT * FROM ``ip_notes`` LEFT JOIN ``mods`` ON `mod` = ``mods``.`id` WHERE ' . $sql_like . ' ORDER BY `time` DESC';
		$sql_table = 'ip_notes';
		if (!hasPermission($config['mod']['view_notes']) || !hasPermission($config['mod']['show_ip']))
			error($config['error']['noaccess']);
	}

	if ($type == 'bans') {
		$query = 'SELECT ``bans``.*, `username` FROM ``bans`` LEFT JOIN ``mods`` ON `creator` = ``mods``.`id` WHERE ' . $sql_like . ' ORDER BY (`expires` IS NOT NULL AND `expires` < UNIX_TIMESTAMP()), `created` DESC';
		$sql_table = 'bans';
		if (!hasPermission($config['mod']['view_banlist']))
			error($config['error']['noaccess']);
	}

	if ($type == 'log') {
		$query = 'SELECT `username`, `mod`, `ip`, `board`, `time`, `text` FROM ``modlogs`` LEFT JOIN ``mods`` ON `mod` = ``mods``.`id` WHERE ' . $sql_like . ' ORDER BY `time` DESC';
		$sql_table = 'modlogs';
		if (!hasPermission($config['mod']['modlog']))
			error($config['error']['noaccess']);
	}

	// Execute SQL query (with pages)
	$q = query($query . ' LIMIT ' . (($page_no - 1) * $config['mod']['search_page']) . ', ' . $config['mod']['search_page']) or error(db_error());
	$results = $q->fetchAll(PDO::FETCH_ASSOC);

	// Get total result count
	if ($type == 'posts') {
		$q = query("SELECT COUNT(*) FROM ($query) AS `tmp_table`") or error(db_error());
		$result_count = $q->fetchColumn();
	} else {
		$q = query('SELECT COUNT(*) FROM `' . $sql_table . '` WHERE ' . $sql_like) or error(db_error());
		$result_count = $q->fetchColumn();
	}

	if ($type == 'bans') {
		foreach ($results as &$ban) {
			$ban['mask'] = Bans::range_to_string(array($ban['ipstart'], $ban['ipend']));
			if (filter_var($ban['mask'], FILTER_VALIDATE_IP) !== false)
				$ban['single_addr'] = true;
		}
	}

	if ($type == 'posts') {
		foreach ($results as &$post) {
			$post['snippet'] = pm_snippet($post['body']);
		}
	}

	// $results now contains the search results

	mod_page(
		_('Search results'),
		$config['file_mod_search_results'],
		[
			'search_type' => $type,
			'search_query' => $search_query,
			'search_query_escaped' => $search_query_escaped,
			'result_count' => $result_count,
			'results' => $results
		],
		$mod
	);
}

function mod_edit_board(Context $ctx, $boardName) {
    global $board, $config, $mod;

    $cache = $ctx->get(CacheDriver::class);

    if (!openBoard($boardName))
        error($config['error']['noboard']);

    if (!hasPermission($config['mod']['manageboards'], $board['uri']))
        error($config['error']['noaccess']);

    if (isset($_POST['title'], $_POST['subtitle'])) {
        if (isset($_POST['delete'])) {
            if (!hasPermission($config['mod']['manageboards'], $board['uri']))
                error($config['error']['deleteboard']);

            // Delete from the single archive table
            $query = prepare('DELETE FROM `archive_threads` WHERE `board_uri` = :uri');
            $query->bindValue(':uri', $board['uri'], PDO::PARAM_STR);
            $query->execute() or error(db_error($query));
            
            // Also delete votes for this board from archive_votes
            $vote_query = prepare('DELETE FROM `archive_votes` WHERE `board` = :uri');
            $vote_query->bindValue(':uri', $board['uri'], PDO::PARAM_STR);
            $vote_query->execute() or error(db_error($vote_query));

            $cache->delete('board_' . $board['uri']);
            $cache->delete('all_boards');

            modLog('Deleted board: ' . sprintf($config['board_abbreviation'], $board['uri']), false);
            
            // Clear reports
            $query = prepare('DELETE FROM ``reports`` WHERE `board` = :id');
            $query->bindValue(':id', $board['uri'], PDO::PARAM_STR);
            $query->execute() or error(db_error($query));

            // Delete all posts for this board from the unified posts table
            $query = prepare('DELETE FROM ``posts`` WHERE `board` = :board');
            $query->bindValue(':board', $board['uri'], PDO::PARAM_STR);
            $query->execute() or error(db_error($query));

            // Delete from boards table
            $query = prepare('DELETE FROM ``boards`` WHERE `uri` = :uri');
            $query->bindValue(':uri', $board['uri'], PDO::PARAM_STR);
            $query->execute() or error(db_error($query));

            // Remove cites
            $query = prepare("SELECT `board`, `post` FROM ``cites`` WHERE `target_board` = :board ORDER BY `board`");
            $query->bindValue(':board', $board['uri']);
            $query->execute() or error(db_error($query));
            while ($cite = $query->fetch(PDO::FETCH_ASSOC)) {
                if ($board['uri'] != $cite['board']) {
                    if (!isset($tmp_board))
                        $tmp_board = $board;
                    openBoard($cite['board']);
                    rebuildPost($cite['post']);
                }
            }
            if (isset($tmp_board))
                $board = $tmp_board;

            $query = prepare('DELETE FROM ``cites`` WHERE `board` = :board OR `target_board` = :board');
            $query->bindValue(':board', $board['uri']);
            $query->execute() or error(db_error($query));

            $query = prepare('DELETE FROM ``antispam`` WHERE `board` = :board');
            $query->bindValue(':board', $board['uri']);
            $query->execute() or error(db_error($query));

            // Remove board from users/permissions table
            $query = query('SELECT `id`,`boards` FROM ``mods``') or error(db_error());
            while ($user = $query->fetch(PDO::FETCH_ASSOC)) {
                $user_boards = explode(',', $user['boards']);
                if (in_array($board['uri'], $user_boards)) {
                    unset($user_boards[array_search($board['uri'], $user_boards)]);
                    $_query = prepare('UPDATE ``mods`` SET `boards` = :boards WHERE `id` = :id');
                    $_query->bindValue(':boards', implode(',', $user_boards));
                    $_query->bindValue(':id', $user['id']);
                    $_query->execute() or error(db_error($_query));
                }
            }
            rrmdir($board['dir']);
        } else {
            $query = prepare('UPDATE ``boards`` SET `title` = :title, `subtitle` = :subtitle WHERE `uri` = :uri');
            $query->bindValue(':uri', $board['uri']);
            $query->bindValue(':title', $_POST['title']);
            $query->bindValue(':subtitle', $_POST['subtitle']);
            $query->execute() or error(db_error($query));
            modLog('Edited board information for ' . sprintf($config['board_abbreviation'], $board['uri']), false);
        }
        $cache->delete('board_' . $board['uri']);
        $cache->delete('all_boards');
        Vichan\Functions\Theme\rebuild_themes('boards');
        header('Location: ?/', true, $config['redirect_http']);
    } else {
        mod_page(
            sprintf('%s: ' . $config['board_abbreviation'], _('Edit board'), $board['uri']),
            $config['file_mod_board'],
            [
                'board' => $board, 
                'token' => make_secure_link_token('edit/' .  $board['prefix'] . $board['uri'])
            ],
            $mod
        );
    }
}

function mod_new_board(Context $ctx) {
	global $board, $mod;
	$config = $ctx->get('config');

	if (!hasPermission($config['mod']['newboard']))
		error($config['error']['noaccess']);

	if (isset($_POST['uri'], $_POST['title'], $_POST['subtitle'])) {
        if ($_POST['uri'] == '')
			error(sprintf($config['error']['required'], 'URI'));

		if ($_POST['title'] == '')
			error(sprintf($config['error']['required'], 'title'));

		if (!preg_match('/^' . $config['board_regex'] . '$/u', $_POST['uri']))
			error(sprintf($config['error']['invalidfield'], 'URI'));

		$cache = $ctx->get(CacheDriver::class);

		$bytes = 0;
		$chars = preg_split('//u', $_POST['uri'], -1, PREG_SPLIT_NO_EMPTY);
		foreach ($chars as $char) {
			$o = 0;
			$ord = ordutf8($char, $o);
			if ($ord > 0x0080)
				$bytes += 5;
			else
				$bytes++;
		}
		$bytes += strlen('posts_.frm');

		if ($bytes > 255) {
			error('Your filesystem cannot handle a board URI of that length (' . $bytes . '/255 bytes)');
			exit;
		}

		if (openBoard($_POST['uri'])) {
			error(sprintf($config['error']['boardexists'], $board['url']));
		}

		$query = prepare('INSERT INTO ``boards`` VALUES (:uri, :title, :subtitle)');
		$query->bindValue(':uri', $_POST['uri']);
		$query->bindValue(':title', $_POST['title']);
		$query->bindValue(':subtitle', $_POST['subtitle']);
		$query->execute() or error(db_error($query));

		modLog('Created a new board: ' . sprintf($config['board_abbreviation'], $_POST['uri']));

		if (!openBoard($_POST['uri']))
			error(_("Couldn't open board after creation."));

		// No per-board archive table creation; archive is global.

		$cache->delete('all_boards');
		buildIndex();
		Vichan\Functions\Theme\rebuild_themes('boards');
		header('Location: ?/', true, $config['redirect_http']);
	}
	mod_page(
		_('New board'),
		$config['file_mod_board'],
		[
			'new' => true,
			'token' => make_secure_link_token('new-board')
		],
		$mod
	);
}

function mod_noticeboard(Context $ctx, $page_no = 1) {
	global $pdo, $mod;
	$config = $ctx->get('config');

	if ($page_no < 1)
		error($config['error']['404']);

	if (!hasPermission($config['mod']['noticeboard']))
		error($config['error']['noaccess']);

	if (isset($_POST['subject'], $_POST['body'])) {
		if (!hasPermission($config['mod']['noticeboard_post']))
			error($config['error']['noaccess']);

		$_POST['body'] = escape_markup_modifiers($_POST['body']);
		markup($_POST['body']);

		$query = prepare('INSERT INTO ``noticeboard`` VALUES (NULL, :mod, :time, :subject, :body)');
		$query->bindValue(':mod', $mod['id']);
		$query->bindvalue(':time', time());
		$query->bindValue(':subject', $_POST['subject']);
		$query->bindValue(':body', $_POST['body']);
		$query->execute() or error(db_error($query));

		$cache = $ctx->get(CacheDriver::class);
		$cache->delete('noticeboard_preview');

		modLog('Posted a noticeboard entry');

		header('Location: ?/noticeboard#' . $pdo->lastInsertId(), true, $config['redirect_http']);
	}

	$query = prepare("SELECT ``noticeboard``.*, `username` FROM ``noticeboard`` LEFT JOIN ``mods`` ON ``mods``.`id` = `mod` ORDER BY `id` DESC LIMIT :offset, :limit");
	$query->bindValue(':limit', $config['mod']['noticeboard_page'], PDO::PARAM_INT);
	$query->bindValue(':offset', ($page_no - 1) * $config['mod']['noticeboard_page'], PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$noticeboard = $query->fetchAll(PDO::FETCH_ASSOC);

	if (empty($noticeboard) && $page_no > 1)
		error($config['error']['404']);

	foreach ($noticeboard as &$entry) {
		$entry['delete_token'] = make_secure_link_token('noticeboard/delete/' . $entry['id']);
	}

	$query = prepare("SELECT COUNT(*) FROM ``noticeboard``");
	$query->execute() or error(db_error($query));
	$count = $query->fetchColumn();

	mod_page(
		_('Noticeboard'),
		$config['file_mod_noticeboard'],
		[
			'noticeboard' => $noticeboard,
			'count' => $count,
			'token' => make_secure_link_token('noticeboard')
		],
		$mod
	);
}

function mod_noticeboard_delete(Context $ctx, $id) {
	$config = $ctx->get('config');

	if (!hasPermission($config['mod']['noticeboard_delete']))
		error($config['error']['noaccess']);

	$query = prepare('DELETE FROM ``noticeboard`` WHERE `id` = :id');
	$query->bindValue(':id', $id);
	$query->execute() or error(db_error($query));

	modLog('Deleted a noticeboard entry');

	$cache = $ctx->get(CacheDriver::class);
	$cache->delete('noticeboard_preview');

	header('Location: ?/noticeboard', true, $config['redirect_http']);
}

function mod_news(Context $ctx, $page_no = 1) {
	global $pdo, $mod;
	$config = $ctx->get('config');

	if ($page_no < 1)
		error($config['error']['404']);

	if (isset($_POST['subject'], $_POST['body'])) {
		if (!hasPermission($config['mod']['news']))
			error($config['error']['noaccess']);

		$_POST['body'] = escape_markup_modifiers($_POST['body']);
		markup($_POST['body']);

		$query = prepare('INSERT INTO ``news`` VALUES (NULL, :name, :time, :subject, :body)');
		$query->bindValue(':name', isset($_POST['name']) && hasPermission($config['mod']['news_custom']) ? $_POST['name'] : $mod['username']);
		$query->bindvalue(':time', time());
		$query->bindValue(':subject', $_POST['subject']);
		$query->bindValue(':body', $_POST['body']);
		$query->execute() or error(db_error($query));

		modLog('Posted a news entry');

		Vichan\Functions\Theme\rebuild_themes('news');

		header('Location: ?/edit_news#' . $pdo->lastInsertId(), true, $config['redirect_http']);
	}

	$query = prepare("SELECT * FROM ``news`` ORDER BY `id` DESC LIMIT :offset, :limit");
	$query->bindValue(':limit', $config['mod']['news_page'], PDO::PARAM_INT);
	$query->bindValue(':offset', ($page_no - 1) * $config['mod']['news_page'], PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$news = $query->fetchAll(PDO::FETCH_ASSOC);

	if (empty($news) && $page_no > 1)
		error($config['error']['404']);

	foreach ($news as &$entry) {
		$entry['delete_token'] = make_secure_link_token('edit_news/delete/' . $entry['id']);
	}

	$query = prepare("SELECT COUNT(*) FROM ``news``");
	$query->execute() or error(db_error($query));
	$count = $query->fetchColumn();

	mod_page(
		_('News'),
		$config['file_mod_news'],
		[
			'news' => $news,
			'count' => $count,
			'token' => make_secure_link_token('edit_news')
		],
		$mod
	);
}

function mod_news_delete(Context $ctx, $id) {
	$config = $ctx->get('config');

	if (!hasPermission($config['mod']['news_delete']))
		error($config['error']['noaccess']);

	$query = prepare('DELETE FROM ``news`` WHERE `id` = :id');
	$query->bindValue(':id', $id);
	$query->execute() or error(db_error($query));

	modLog('Deleted a news entry');

	header('Location: ?/edit_news', true, $config['redirect_http']);
}

function mod_log(Context $ctx, $page_no = 1) {
	global $mod;
	$config = $ctx->get('config');

	if ($page_no < 1)
		error($config['error']['404']);

	if (!hasPermission($config['mod']['modlog']))
		error($config['error']['noaccess']);

	$query = prepare("SELECT `username`, `mod`, `ip`, `board`, `time`, `text` FROM ``modlogs`` LEFT JOIN ``mods`` ON `mod` = ``mods``.`id` ORDER BY `time` DESC LIMIT :offset, :limit");
	$query->bindValue(':limit', $config['mod']['modlog_page'], PDO::PARAM_INT);
	$query->bindValue(':offset', ($page_no - 1) * $config['mod']['modlog_page'], PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$logs = $query->fetchAll(PDO::FETCH_ASSOC);

	if (empty($logs) && $page_no > 1)
		error($config['error']['404']);

	$query = prepare("SELECT COUNT(*) FROM ``modlogs``");
	$query->execute() or error(db_error($query));
	$count = $query->fetchColumn();

	mod_page(_('Moderation log'), $config['file_mod_log'], [ 'logs' => $logs, 'count' => $count ], $mod);
}

function mod_user_log(Context $ctx, $username, $page_no = 1) {
	global $mod;
	$config = $ctx->get('config');

	if ($page_no < 1)
		error($config['error']['404']);

	if (!hasPermission($config['mod']['modlog']))
		error($config['error']['noaccess']);

	$query = prepare("SELECT `username`, `mod`, `ip`, `board`, `time`, `text` FROM ``modlogs`` LEFT JOIN ``mods`` ON `mod` = ``mods``.`id` WHERE `username` = :username ORDER BY `time` DESC LIMIT :offset, :limit");
	$query->bindValue(':username', $username);
	$query->bindValue(':limit', $config['mod']['modlog_page'], PDO::PARAM_INT);
	$query->bindValue(':offset', ($page_no - 1) * $config['mod']['modlog_page'], PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$logs = $query->fetchAll(PDO::FETCH_ASSOC);

	if (empty($logs) && $page_no > 1)
		error($config['error']['404']);

	$query = prepare("SELECT COUNT(*) FROM ``modlogs`` LEFT JOIN ``mods`` ON `mod` = ``mods``.`id` WHERE `username` = :username");
	$query->bindValue(':username', $username);
	$query->execute() or error(db_error($query));
	$count = $query->fetchColumn();

	mod_page(_('Moderation log'), $config['file_mod_log'], [ 'logs' => $logs, 'count' => $count, 'username' => $username ], $mod);
}

function mod_board_log(Context $ctx, $board, $page_no = 1, $hide_names = false, $public = false) {
	global $mod;
	$config = $ctx->get('config');

	if ($page_no < 1)
		error($config['error']['404']);

	if (!hasPermission($config['mod']['mod_board_log'], $board) && !$public)
		error($config['error']['noaccess']);

	$query = prepare("SELECT `username`, `mod`, `ip`, `board`, `time`, `text` FROM ``modlogs`` LEFT JOIN ``mods`` ON `mod` = ``mods``.`id` WHERE `board` = :board ORDER BY `time` DESC LIMIT :offset, :limit");
	$query->bindValue(':board', $board);
	$query->bindValue(':limit', $config['mod']['modlog_page'], PDO::PARAM_INT);
	$query->bindValue(':offset', ($page_no - 1) * $config['mod']['modlog_page'], PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$logs = $query->fetchAll(PDO::FETCH_ASSOC);

	if (empty($logs) && $page_no > 1)
		error($config['error']['404']);

	if (!hasPermission($config['mod']['show_ip'])) {
		// Supports ipv4 only!
		foreach ($logs as $i => &$log) {
			$log['text'] = preg_replace_callback('/(?:<a href="\?\/IP\/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}">)?(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})(?:<\/a>)?/', function($matches) {
				return "xxxx";//less_ip($matches[1]);
			}, $log['text']);
		}
	}

	$query = prepare("SELECT COUNT(*) FROM ``modlogs`` LEFT JOIN ``mods`` ON `mod` = ``mods``.`id` WHERE `board` = :board");
	$query->bindValue(':board', $board);
	$query->execute() or error(db_error($query));
	$count = $query->fetchColumn();

	mod_page(
		_('Board log'),
		$config['file_mod_log'],
		[
			'logs' => $logs,
			'count' => $count,
			'board' => $board,
			'hide_names' => $hide_names,
			'public' => $public
		],
		$mod
	);
}

function mod_view_catalog(Context $ctx, $boardName) {
	$config = $ctx->get('config');
	require_once($config['dir']['themes'].'/catalog/theme.php');
	$settings = [];
	$settings['boards'] = $boardName;
	$settings['update_on_posts'] = true;
	$settings['title'] = 'Catalog';
	$settings['use_tooltipster'] = true;
	$catalog = new Catalog();
	echo $catalog->build($settings, $boardName, true);
}

function mod_view_board(Context $ctx, $boardName, $folder = null, $page_no = 1) {
	global $mod;
	$config = $ctx->get('config');

	if (!openBoard($boardName))
		error($config['error']['noboard']);

	// Support both old and new pagination: if $folder is set, $page_no is the 3rd argument (from /pagination/{folder}/{page}.html)
	if (is_numeric($folder) && is_numeric($page_no)) {
		$page_no = (int)$page_no;
	} elseif (is_numeric($folder) && !is_numeric($page_no)) {
		// fallback: /pagination/2.html (shouldn't happen, but just in case)
		$page_no = (int)$folder;
	}

	if (!$page_no || $page_no < 1) $page_no = 1;

	if (!$page = index($page_no, $mod)) {
		error($config['error']['404']);
	}

	$page['pages'] = getPages(true);
	if (isset($page['pages'][$page_no - 1])) {
		$page['pages'][$page_no - 1]['selected'] = true;
	}
	$page['btn'] = getPageButtons($page['pages'], true);
	$page['mod'] = true;
	$page['config'] = $config;
	$page['pm'] = create_pm_header();

	echo Element($config['file_board_index'], $page);
}

function mod_view_thread(Context $ctx, $boardName, $live_date_path, $thread) {
	global $mod;
	$config = $ctx->get('config');

	if (!openBoard($boardName))
		error($config['error']['noboard']);

	// Only pass the thread ID to buildThread
	$page = buildThread($thread, true, $mod);
	echo $page;
}

function mod_view_thread50(Context $ctx, $boardName, $live_date_path, $thread) {
	global $mod;
	$config = $ctx->get('config');

	if (!openBoard($boardName))
		error($config['error']['noboard']);

	// Only pass the thread ID to buildThread50
	$page = buildThread50($thread, true, $mod);
	echo $page;
}

function mod_ip_remove_note(Context $ctx, $cloaked_ip, $id) {
	$ip = uncloak_ip($cloaked_ip);
	$config = $ctx->get('config');

	if (!hasPermission($config['mod']['remove_notes']))
		error($config['error']['noaccess']);

	if (filter_var($ip, FILTER_VALIDATE_IP) === false)
		error("Invalid IP address.");

	$query = prepare('DELETE FROM ``ip_notes`` WHERE `ip` = :ip AND `id` = :id');
	$query->bindValue(':ip', $ip);
	$query->bindValue(':id', $id);
	$query->execute() or error(db_error($query));

	modLog("Removed a note for <a href=\"?/IP/{$cloaked_ip}\">{$cloaked_ip}</a>");

	header('Location: ?/IP/' . $cloaked_ip . '#notes', true, $config['redirect_http']);
}



function mod_ip(Context $ctx, $cip) {
	$ip = uncloak_ip($cip);
	global $mod;
	$config = $ctx->get('config');

	if (filter_var($ip, FILTER_VALIDATE_IP) === false)
		error("Invalid IP address.");

	if (isset($_POST['ban_id'], $_POST['unban'])) {
		if (!hasPermission($config['mod']['unban']))
			error($config['error']['noaccess']);

		Bans::delete($_POST['ban_id'], true, $mod['boards']);

		header('Location: ?/IP/' . $cip . '#bans', true, $config['redirect_http']);
		return;
	}

	if (isset($_POST['ban_id'], $_POST['edit_ban'])) {
		if (!hasPermission($config['mod']['edit_ban']))
			error($config['error']['noaccess']);

		header('Location: ?/edit_ban/' . $_POST['ban_id'], true, $config['redirect_http']);
		return;
	}

	if (isset($_POST['note'])) {
		if (!hasPermission($config['mod']['create_notes']))
			error($config['error']['noaccess']);

		$_POST['note'] = escape_markup_modifiers($_POST['note']);
		markup($_POST['note']);
		$query = prepare('INSERT INTO ``ip_notes`` VALUES (NULL, :ip, :mod, :time, :body)');
		$query->bindValue(':ip', $ip);
		$query->bindValue(':mod', $mod['id']);
		$query->bindValue(':time', time());
		$query->bindValue(':body', $_POST['note']);
		$query->execute() or error(db_error($query));

		modLog("Added a note for <a href=\"?/IP/{$cip}\">{$cip}</a>");

		header('Location: ?/IP/' . $cip . '#notes', true, $config['redirect_http']);
		return;
	}


	$args = [];
	$args['ip'] = $ip;
	$args['posts'] = [];

	if ($config['mod']['dns_lookup'] && empty($config['ipcrypt_key']))
		$args['hostname'] = rDNS($ip);

	$boards = listBoards();
	foreach ($boards as $board) {
		openBoard($board['uri']);
		if (!hasPermission($config['mod']['show_ip'], $board['uri']))
			continue;
		$query = prepare('SELECT * FROM ``posts`` WHERE `board` = :board AND `ip` = :ip ORDER BY `sticky` DESC, `id` DESC LIMIT :limit');
		$query->bindValue(':board', $board['uri']);
		$query->bindValue(':ip', $ip);
		$query->bindValue(':limit', $config['mod']['ip_recentposts'], PDO::PARAM_INT);
		$query->execute() or error(db_error($query));

		while ($post = $query->fetch(PDO::FETCH_ASSOC)) {
			if (!$post['thread']) {
				$po = new Thread($post, '?/', $mod, false);
			} else {
				$po = new Post($post, '?/', $mod);
			}

			if (!isset($args['posts'][$board['uri']]))
				$args['posts'][$board['uri']] = array('board' => $board, 'posts' => []);
			$args['posts'][$board['uri']]['posts'][] = $po->build(true);
		}
	}

	$args['boards'] = $boards;
	$args['token'] = make_secure_link_token('ban');

	if (hasPermission($config['mod']['view_ban'])) {
		$args['bans'] = Bans::find($ip, false, true, null, $config['auto_maintenance']);
	}

	if (hasPermission($config['mod']['view_notes'])) {
		$query = prepare("SELECT ``ip_notes``.*, `username` FROM ``ip_notes`` LEFT JOIN ``mods`` ON `mod` = ``mods``.`id` WHERE `ip` = :ip ORDER BY `time` DESC");
		$query->bindValue(':ip', $ip);
		$query->execute() or error(db_error($query));
		$args['notes'] = $query->fetchAll(PDO::FETCH_ASSOC);
	}

	if (hasPermission($config['mod']['modlog_ip'])) {
		$query = prepare("SELECT `username`, `mod`, `ip`, `board`, `time`, `text` FROM ``modlogs`` LEFT JOIN ``mods`` ON `mod` = ``mods``.`id` WHERE `text` LIKE :search ORDER BY `time` DESC LIMIT 50");
		$query->bindValue(':search', '%' . $cip . '%');
		$query->execute() or error(db_error($query));
		$args['logs'] = $query->fetchAll(PDO::FETCH_ASSOC);
	} else {
		$args['logs'] = [];
	}

	$args['security_token'] = make_secure_link_token('IP/' . $cip);

	mod_page(sprintf('%s: %s', _('IP'), htmlspecialchars($cip)), $config['file_mod_view_ip'], $args, $mod, $args['hostname']);
}

function mod_edit_ban(Context $ctx, $ban_id) {
	global $mod;
	$config = $ctx->get('config');

	if (!hasPermission($config['mod']['edit_ban']))
		error($config['error']['noaccess']);

	$args['bans'] = Bans::find(null, false, true, $ban_id, $config['auto_maintenance']);
	$args['ban_id'] = $ban_id;
	$args['boards'] = listBoards();
	$args['current_board'] = isset($args['bans'][0]['board']) ? $args['bans'][0]['board'] : false;

	if (!$args['bans'])
		error($config['error']['404']);

	if (isset($_POST['new_ban'])) {

		$new_ban['mask'] = $args['bans'][0]['mask'];
		$new_ban['post'] = isset($args['bans'][0]['post']) ? $args['bans'][0]['post'] : false;
		$new_ban['board'] = $args['current_board'];

		if (isset($_POST['reason']))
			$new_ban['reason'] = $_POST['reason'];
		else
			$new_ban['reason'] = $args['bans'][0]['reason'];

		if (isset($_POST['length']) && !empty($_POST['length']))
			$new_ban['length'] = $_POST['length'];
		else
			$new_ban['length'] = false;

		if (isset($_POST['board'])) {
			if ($_POST['board'] == '*')
				$new_ban['board'] = false;
			else
				$new_ban['board'] = $_POST['board'];
		}

		Bans::new_ban($new_ban['mask'], $new_ban['reason'], $new_ban['length'], $new_ban['board'], false, $new_ban['post']);
		Bans::delete($ban_id);

		header('Location: ?/', true, $config['redirect_http']);
	}

	$args['token'] = make_secure_link_token('edit_ban/' . $ban_id);

	mod_page(_('Edit ban'), 'mod/edit_ban.html', $args, $mod);
}

function mod_ban(Context $ctx) {
	global $mod;
	$config = $ctx->get('config');

	if (!hasPermission($config['mod']['ban']))
		error($config['error']['noaccess']);

	if (!isset($_POST['ip'], $_POST['reason'], $_POST['length'], $_POST['board'])) {
		mod_page(_('New ban'), $config['file_mod_ban_form'], [ 'token' => make_secure_link_token('ban') ], $mod);
		return;
	}

	Bans::new_ban($_POST['ip'], $_POST['reason'], $_POST['length'], $_POST['board'] == '*' ? false : $_POST['board']);

	if (isset($_POST['redirect']))
		header('Location: ' . $_POST['redirect'], true, $config['redirect_http']);
	else
		header('Location: ?/', true, $config['redirect_http']);
}

function mod_bans(Context $ctx) {
	global $mod;
	$config = $ctx->get('config');

	if (!hasPermission($config['mod']['view_banlist']))
		error($config['error']['noaccess']);

	if (isset($_POST['unban'])) {
		if (!hasPermission($config['mod']['unban']))
			error($config['error']['noaccess']);

		$unban = [];
		foreach ($_POST as $name => $unused) {
			if (preg_match('/^ban_(\d+)$/', $name, $match))
				$unban[] = $match[1];
		}
		if (isset($config['mod']['unban_limit']) && $config['mod']['unban_limit'] && count($unban) > $config['mod']['unban_limit'])
			error(sprintf($config['error']['toomanyunban'], $config['mod']['unban_limit'], count($unban)));

		foreach ($unban as $id) {
			Bans::delete($id, true, $mod['boards'], true);
		}
		Vichan\Functions\Theme\rebuild_themes('bans');
		header('Location: ?/bans', true, $config['redirect_http']);
		return;
	}

	mod_page(
		_('Ban list'),
		$config['file_mod_ban_list'],
		[
			'mod' => $mod,
			'boards' => json_encode($mod['boards']),
			'token' => make_secure_link_token('bans'),
			'token_json' => make_secure_link_token('bans.json')
		],
		$mod
	);
}

function mod_bans_json(Context $ctx) {
	global $mod;
	$config = $ctx->get('config');

	if (!hasPermission($config['mod']['ban']))
		error($config['error']['noaccess']);

	// Compress the json for faster loads
	if (substr_count($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip')) ob_start("ob_gzhandler");

	Bans::stream_json(false, false, !hasPermission($config['mod']['view_banstaff']), $mod['boards']);
}

function mod_ban_appeals(Context $ctx) {
	global $board, $mod;
	$config = $ctx->get('config');

	if (!hasPermission($config['mod']['view_ban_appeals']))
		error($config['error']['noaccess']);

	if (isset($_POST['appeal_id']) && (isset($_POST['unban']) || isset($_POST['deny']))) {
		if (!hasPermission($config['mod']['ban_appeals']))
			error($config['error']['noaccess']);

		$query = query("SELECT *, ``ban_appeals``.`id` AS `id` FROM ``ban_appeals``
			LEFT JOIN ``bans`` ON `ban_id` = ``bans``.`id`
			WHERE ``ban_appeals``.`id` = " . (int)$_POST['appeal_id']) or error(db_error());
		if (!$ban = $query->fetch(PDO::FETCH_ASSOC)) {
			error(_('Ban appeal not found!'));
		}

		$ban['mask'] = cloak_mask(Bans::range_to_string(array($ban['ipstart'], $ban['ipend'])));

		if (isset($_POST['unban'])) {
			modLog('Accepted ban appeal #' . $ban['id'] . ' for ' . $ban['mask']);
			Bans::delete($ban['ban_id'], true);
			query("DELETE FROM ``ban_appeals`` WHERE `id` = " . $ban['id']) or error(db_error());
		} else {
			modLog('Denied ban appeal #' . $ban['id'] . ' for ' . $ban['mask']);
			query("UPDATE ``ban_appeals`` SET `denied` = 1 WHERE `id` = " . $ban['id']) or error(db_error());
		}

		header('Location: ?/ban-appeals', true, $config['redirect_http']);
		return;
	}

	$query = query("SELECT *, ``ban_appeals``.`id` AS `id` FROM ``ban_appeals``
		LEFT JOIN ``bans`` ON `ban_id` = ``bans``.`id`
		LEFT JOIN ``mods`` ON ``bans``.`creator` = ``mods``.`id`
		WHERE `denied` != 1 ORDER BY `time`") or error(db_error());
	$ban_appeals = $query->fetchAll(PDO::FETCH_ASSOC);
	foreach ($ban_appeals as &$ban) {
		if ($ban['post'])
			$ban['post'] = json_decode($ban['post'], true);
		$ban['mask'] = Bans::range_to_string(array($ban['ipstart'], $ban['ipend']));

		if ($ban['post'] && isset($ban['post']['board'], $ban['post']['id'])) {
			if (openBoard($ban['post']['board'])) {
				$query = prepare("SELECT `num_files`, `files` FROM ``posts`` WHERE `board` = :board AND `id` = :id");
				$query->bindValue(':board', $board['uri']);
				$query->bindValue(':id', (int)$ban['post']['id']);
				$query->execute() or error(db_error($query));
				if ($_post = $query->fetch(PDO::FETCH_ASSOC)) {
					$_post['files'] = $_post['files'] ? json_decode($_post['files']) : [];
					$ban['post'] = array_merge($ban['post'], $_post);
				} else {
					$ban['post']['files'] = array([]);
					$ban['post']['files'][0]['file'] = 'deleted';
					$ban['post']['files'][0]['thumb'] = false;
					$ban['post']['num_files'] = 1;
				}
			} else {
				$ban['post']['files'] = array([]);
				$ban['post']['files'][0]['file'] = 'deleted';
				$ban['post']['files'][0]['thumb'] = false;
				$ban['post']['num_files'] = 1;
			}

			if ($ban['post']['thread']) {
				$ban['post'] = new Post($ban['post']);
			} else {
				$ban['post'] = new Thread($ban['post'], null, false, false);
			}
		}
	}

	mod_page(
		_('Ban appeals'),
		$config['file_mod_ban_appeals'],
		[
			'ban_appeals' => $ban_appeals,
			'token' => make_secure_link_token('ban-appeals')
		],
		$mod
	);
}

function mod_lock(Context $ctx, $board, $unlock, $post) {
    $config = $ctx->get('config');

    // Extract the board URI (e.g., "b") from the full board path (e.g., "channel/b")
    $board_uri = basename($board);

    if (!openBoard($board_uri)) {
        error($config['error']['noboard']);
    }

    if (!hasPermission($config['mod']['lock'], $board_uri)) {
        error($config['error']['noaccess']);
    }

    // Use the board URI to construct the table name
    $query = prepare('UPDATE ``posts`` SET `locked` = :locked WHERE `board` = :board AND `id` = :id AND `thread` IS NULL');
	$query->bindValue(':board', $board_uri);
    $query->bindValue(':id', $post);
    $query->bindValue(':locked', $unlock ? 0 : 1);
    $query->execute() or error(db_error($query));

    if ($query->rowCount()) {
        modLog(($unlock ? 'Unlocked' : 'Locked') . " thread #{$post}");
        buildThread($post);
        buildIndex();
    }

    if ($config['mod']['dismiss_reports_on_lock']) {
        $query = prepare('DELETE FROM ``reports`` WHERE `board` = :board AND `post` = :id');
        $query->bindValue(':board', $board_uri);
        $query->bindValue(':id', $post);
        $query->execute() or error(db_error($query));
    }

    // Redirect to the board index
    header('Location: ?/' . sprintf($config['board_path'], $board_uri) . $config['file_index'], true, $config['redirect_http']);

    if ($unlock) {
        event('unlock', $post);
    } else {
        event('lock', $post);
    }
}

function mod_sticky(Context $ctx, $board, $unsticky, $post) {
    $config = $ctx->get('config');

    // Extract the board URI (e.g., "b") from the full board path (e.g., "channel/b")
    $board_uri = basename($board);

    if (!openBoard($board_uri)) {
        error($config['error']['noboard']);
    }

    if (!hasPermission($config['mod']['sticky'], $board_uri)) {
        error($config['error']['noaccess']);
    }

    // Correct: update the `sticky` column, not `locked`
    $query = prepare('UPDATE ``posts`` SET `sticky` = :sticky WHERE `board` = :board AND `id` = :id AND `thread` IS NULL');
    $query->bindValue(':board', $board_uri);
    $query->bindValue(':id', $post);
    $query->bindValue(':sticky', $unsticky ? 0 : 1);
    $query->execute() or error(db_error($query));

    if ($query->rowCount()) {
        modLog(($unsticky ? 'Unstickied' : 'Stickied') . " thread #{$post}");
        buildThread($post);
        buildIndex();
    }

    // Redirect to the board index
    header('Location: ?/' . sprintf($config['board_path'], $board_uri) . $config['file_index'], true, $config['redirect_http']);
}

function mod_cycle(Context $ctx, $board, $uncycle, $post) {
    $config = $ctx->get('config');

    // Extract the board URI (e.g., "b") from the full board path (e.g., "channel/b")
    $board_uri = basename($board);

    if (!openBoard($board_uri)) {
        error($config['error']['noboard']);
    }

    if (!hasPermission($config['mod']['cycle'], $board_uri)) {
        error($config['error']['noaccess']);
    }

    // Use the board URI to construct the table name
    $query = prepare('UPDATE ``posts`` SET `cycle` = :cycle WHERE `board` = :board AND `id` = :id AND `thread` IS NULL');
	$query->bindValue(':board', $board_uri);
	$query->bindValue(':id', $post);
	$query->bindValue(':cycle', $uncycle ? 0 : 1);
	$query->execute() or error(db_error($query));

    if ($query->rowCount()) {
        modLog(($uncycle ? 'Made not cyclical' : 'Made cyclical') . " thread #{$post}");
        buildThread($post);
        buildIndex();
    }

    // Redirect to the board index
    header('Location: ?/' . sprintf($config['board_path'], $board_uri) . $config['file_index'], true, $config['redirect_http']);
}

function mod_bumplock(Context $ctx, $board, $unbumplock, $post) {
    $config = $ctx->get('config');

    // Extract the board URI (e.g., "b") from the full board path (e.g., "channel/b")
    $board_uri = basename($board);

    if (!openBoard($board_uri)) {
        error($config['error']['noboard']);
    }

    if (!hasPermission($config['mod']['bumplock'], $board_uri)) {
        error($config['error']['noaccess']);
    }

    // Correct: update the `sage` column, not `locked`
    $query = prepare('UPDATE ``posts`` SET `sage` = :sage WHERE `board` = :board AND `id` = :id AND `thread` IS NULL');
    $query->bindValue(':board', $board_uri);
    $query->bindValue(':id', $post);
    $query->bindValue(':sage', $unbumplock ? 0 : 1);
    $query->execute() or error(db_error($query));

    if ($query->rowCount()) {
        modLog(($unbumplock ? 'Unbumplocked' : 'Bumplocked') . " thread #{$post}");
        buildThread($post);
        buildIndex();
    }

    // Redirect to the board index
    header('Location: ?/' . sprintf($config['board_path'], $board_uri) . $config['file_index'], true, $config['redirect_http']);
}

function mod_move_reply(Context $ctx, $originBoard, $postID) {
    global $board, $config, $mod;

    // Extract the board URI (e.g., "3e") from the full board path (e.g., "channel/3e")
    $originBoardURI = basename($originBoard);

    if (!openBoard($originBoardURI)) {
        error($config['error']['noboard']);
    }

    if (!hasPermission($config['mod']['move'], $originBoardURI)) {
        error($config['error']['noaccess']);
    }

    // Fetch the post, including live_date_path
    $query = prepare('SELECT * FROM ``posts`` WHERE `board` = :board AND `id` = :id');
    $query->bindValue(':board', $originBoardURI);
    $query->bindValue(':id', $postID);
    $query->execute() or error(db_error($query));
    if (!$post = $query->fetch(PDO::FETCH_ASSOC)) {
        error($config['error']['404']);
    }

    if (isset($_POST['board'])) {
        $targetBoard = basename($_POST['board']); // Extract the target board URI

        if ($_POST['target_thread']) {
            $query = prepare('SELECT * FROM ``posts`` WHERE `board` = :board AND `id` = :id');
            $query->bindValue(':board', $targetBoard);
            $query->bindValue(':id', $_POST['target_thread']);
            $query->execute() or error(db_error($query)); // If it fails, thread probably does not exist
            $post['op'] = false;
            $post['thread'] = $_POST['target_thread'];
        } else {
            $post['op'] = true;
        }

        // Prepare file paths with live_date_path
        if ($post['files']) {
            $post['files'] = json_decode($post['files'], true);
            $post['has_file'] = true;
            foreach ($post['files'] as $i => &$file) {
                if ($file['file'] === 'deleted') {
                    continue;
                }
                $file['file_path'] = sprintf($config['board_path'], $originBoardURI) . $config['dir']['img'] . $file['file'];
                if (isset($file['thumb']) && $file['thumb'] && $file['thumb'] !== 'deleted') {
                    $file['thumb_path'] = sprintf($config['board_path'], $originBoardURI) . $config['dir']['thumb'] . $file['thumb'];
                }
            }
        } else {
            $post['has_file'] = false;
        }

        // Allow thread to keep its same traits (stickied, locked, etc.)
        $post['mod'] = true;

        if (!openBoard($targetBoard)) {
            error($config['error']['noboard']);
        }

        // Create the new post
        $newID = post($post);

        // Fetch live_date_path for the new post on the target board
        $query = prepare('SELECT live_date_path FROM ``posts`` WHERE `board` = :board AND `id` = :id');
        $query->bindValue(':board', $targetBoard);
        $query->bindValue(':id', $newID);
        $query->execute() or error(db_error($query));
        $new_live_date_path = $query->fetchColumn();

        // Move files to the new board and live_date_path
        if ($post['has_file']) {
            foreach ($post['files'] as $i => &$file) {
                if ($file['file'] === 'deleted') {
                    continue;
                }
                $target_img_path = sprintf($config['board_path'], $targetBoard) . $config['dir']['img'] . $file['file'];
                @rename($file['file_path'], $target_img_path);
                if (isset($file['thumb']) && $file['thumb'] && $file['thumb'] !== 'deleted' && $file['thumb'] !== 'spoiler') {
                    $target_thumb_path = sprintf($config['board_path'], $targetBoard) . $config['dir']['thumb'] . $file['thumb'];
                    @rename($file['thumb_path'], $target_thumb_path);
                }
            }
        }

        // Build index and thread
        buildIndex();
        buildThread($newID);

        // Trigger themes
        Vichan\Functions\Theme\rebuild_themes('post', $targetBoard);

        // Log the action
        modLog("Moved post #{$postID} to " . sprintf($config['board_abbreviation'], $targetBoard) . " (#{$newID})", $originBoardURI);

        // Return to the original board
        openBoard($originBoardURI);

        // Delete the original post
        deletePost($postID);
        buildIndex();

        // Open the target board for redirect
        openBoard($targetBoard);

        // Find the new reply's thread and its live_date_path
		$query = prepare('SELECT thread FROM ``posts`` WHERE `board` = :board AND `id` = :id');
		$query->bindValue(':board', $targetBoard);
		$query->bindValue(':id', $newID);
		$query->execute() or error(db_error($query));
		$thread_id = $query->fetchColumn();

		// If this is a reply, fetch the OP post for the thread
		if ($thread_id) {
			$query = prepare('SELECT * FROM ``posts`` WHERE `board` = :board AND `id` = :id');
			$query->bindValue(':board', $targetBoard);
			$query->bindValue(':id', $thread_id);
			$query->execute() or error(db_error($query));
			$thread_post = $query->fetch(PDO::FETCH_ASSOC);

			// Redirect to the thread, anchored to the reply
			header('Location: ?/' . sprintf($config['board_path'], $targetBoard) . $config['dir']['res'] . $thread_post['live_date_path'] . '/' . link_for($thread_post) . '#' . $newID, true, $config['redirect_http']);
		} else {
			// If this is a new thread, redirect to it
			$query = prepare('SELECT * FROM ``posts`` WHERE `board` = :board AND `id` = :id');
			$query->bindValue(':board', $targetBoard);
			$query->bindValue(':id', $newID);
			$query->execute() or error(db_error($query));
			$thread_post = $query->fetch(PDO::FETCH_ASSOC);

			header('Location: ?/' . sprintf($config['board_path'], $targetBoard) . $config['dir']['res'] . $thread_post['live_date_path'] . '/' . link_for($thread_post) . '#' . $newID, true, $config['redirect_http']);
		}
    } else {
        $boards = listBoards();

        $board_path = rtrim(sprintf($config['board_path'], $originBoardURI), '/');
        $security_token = make_secure_link_token("{$board_path}/move_reply/{$postID}");

        mod_page(
            _('Move reply'),
            $config['file_mod_move_reply'],
            [
                'post' => $postID,
                'board' => $originBoardURI,
                'boards' => $boards,
                'token' => $security_token
            ],
            $mod
        );
    }
}

function mod_move(Context $ctx, $originBoard, $postID) {
    global $board, $config, $pdo, $mod;

    $originBoardURI = basename($originBoard);
    error_log("mod_move: Starting with originBoardURI=$originBoardURI, postID=$postID");

    if (!openBoard($originBoardURI)) {
        error($config['error']['noboard']);
    }

    if (!hasPermission($config['mod']['move'], $originBoardURI)) {
        error($config['error']['noaccess']);
    }

    // Fetch the OP post, including live_date_path
    $query = prepare('SELECT * FROM ``posts`` WHERE `board` = :board AND `id` = :id AND `thread` IS NULL');
    $query->bindValue(':board', $originBoardURI);
    $query->bindValue(':id', $postID);
    $query->execute() or error(db_error($query));
    if (!$post = $query->fetch(PDO::FETCH_ASSOC)) {
        error($config['error']['404']);
    }
    error_log("mod_move: Fetched OP post with ID=$postID, live_date_path={$post['live_date_path']}");

    if (isset($_POST['board'])) {
        $targetBoard = basename($_POST['board']);
        $shadow = isset($_POST['shadow']);
        error_log("mod_move: Target board=$targetBoard, shadow=" . ($shadow ? 'true' : 'false'));

        if ($targetBoard === $originBoardURI) {
            error(_('Target and source board are the same.'));
        }

        $clone = $shadow ? '_link_or_copy' : 'rename';
        $post['op'] = true;

        // Prepare file paths for OP
        if ($post['files']) {
            $post['files'] = json_decode($post['files'], true);
            $post['has_file'] = true;
            foreach ($post['files'] as $i => &$file) {
                if ($file['file'] === 'deleted') {
                    continue;
                }
                $file['file_path'] = sprintf($config['board_path'], $originBoardURI) . $config['dir']['img'] . $file['file'];
                $file['thumb_path'] = sprintf($config['board_path'], $originBoardURI) . $config['dir']['thumb'] . $file['thumb'];
                error_log("mod_move: OP file path={$file['file_path']}, thumb path={$file['thumb_path']}");
            }
        } else {
            $post['has_file'] = false;
        }

        $post['mod'] = true;

        if (!openBoard($targetBoard)) {
            error($config['error']['noboard']);
        }

        // Create the new thread
        $newID = post($post);
        error_log("mod_move: Created new thread on target board with newID=$newID");

        // Fetch live_date_path for the new thread on the target board
        $op = $post;
        $op['id'] = $newID;
        $query = prepare('SELECT live_date_path FROM ``posts`` WHERE `board` = :board AND `id` = :id');
        $query->bindValue(':board', $targetBoard);
        $query->bindValue(':id', $newID);
        $query->execute() or error(db_error($query));
        $op['live_date_path'] = $query->fetchColumn();
        error_log("mod_move: New thread live_date_path={$op['live_date_path']}");

        // Move/copy files for OP
        if ($post['has_file']) {
            foreach ($post['files'] as $i => &$file) {
                if ($file['file'] !== 'deleted') {
                    $target_img_path = sprintf($config['board_path'], $targetBoard) . $config['dir']['img'] . $file['file'];
                    $target_img_dir = dirname($target_img_path);
                    if (!is_dir($target_img_dir)) {
                        mkdir($target_img_dir, 0775, true);
                    }
                    if (!$clone($file['file_path'], $target_img_path)) {
                        error_log("mod_move: Failed to move/copy image: {$file['file_path']} -> $target_img_path");
                    }
                    if (isset($file['thumb']) && !in_array($file['thumb'], ['spoiler', 'deleted', 'file'])) {
                        $target_thumb_path = sprintf($config['board_path'], $targetBoard) . $config['dir']['thumb'] . $file['thumb'];
                        $target_thumb_dir = dirname($target_thumb_path);
                        if (!is_dir($target_thumb_dir)) {
                            mkdir($target_thumb_dir, 0775, true);
                        }
                        if (!$clone($file['thumb_path'], $target_thumb_path)) {
                            error_log("mod_move: Failed to move/copy thumb: {$file['thumb_path']} -> $target_thumb_path");
                        }
                    }
                }
            }
        }

        // Fetch replies to the thread
        openBoard($originBoardURI);
        $query = prepare('SELECT * FROM ``posts`` WHERE `board` = :board AND `thread` = :id ORDER BY `id`');
        $query->bindValue(':board', $originBoardURI);
        $query->bindValue(':id', $postID, PDO::PARAM_INT);
        $query->execute() or error(db_error($query));

        $replies = [];
        while ($reply = $query->fetch(PDO::FETCH_ASSOC)) {
            $reply['mod'] = true;
            $reply['thread'] = $newID;
            $reply_live_date_path = $reply['live_date_path'];
            error_log("mod_move: Reply ID={$reply['id']}, live_date_path=$reply_live_date_path");

            if ($reply['files']) {
                $reply['files'] = json_decode($reply['files'], true);
                $reply['has_file'] = true;
                foreach ($reply['files'] as $i => &$file) {
                    $file['file_path'] = sprintf($config['board_path'], $originBoardURI) . $config['dir']['img'] . $file['file'];
                    if (isset($file['thumb'])) {
                        $file['thumb_path'] = sprintf($config['board_path'], $originBoardURI) . $config['dir']['thumb'] . $file['thumb'];
                    }
                    error_log("mod_move: Reply file path={$file['file_path']}, thumb path=" . (isset($file['thumb_path']) ? $file['thumb_path'] : 'none'));
                }
            } else {
                $reply['has_file'] = false;
            }

            $replies[] = $reply;
        }

        $newIDs = [$postID => $newID];

        openBoard($targetBoard);

        foreach ($replies as &$reply) {
            $reply['op'] = false;
            $reply['tracked_cites'] = markup($reply['body'], true);

            if ($reply['has_file']) {
                foreach ($reply['files'] as $i => &$file) {
                    if ($file['file'] !== 'deleted') {
                        $target_img_path = sprintf($config['board_path'], $targetBoard) . $config['dir']['img'] . $reply['live_date_path'] . '/' . $file['file'];
                        $target_img_dir = dirname($target_img_path);
                        if (!is_dir($target_img_dir)) {
                            mkdir($target_img_dir, 0775, true);
                        }
                        if (!$clone($file['file_path'], $target_img_path)) {
                            error_log("mod_move: Failed to move/copy reply image: {$file['file_path']} -> $target_img_path");
                        }
                        if (isset($file['thumb']) && !in_array($file['thumb'], ['spoiler', 'deleted', 'file'])) {
                            $target_thumb_path = sprintf($config['board_path'], $targetBoard) . $config['dir']['thumb'] . $reply['live_date_path'] . '/' . $file['thumb'];
                            $target_thumb_dir = dirname($target_thumb_path);
                            if (!is_dir($target_thumb_dir)) {
                                mkdir($target_thumb_dir, 0775, true);
                            }
                            if (!$clone($file['thumb_path'], $target_thumb_path)) {
                                error_log("mod_move: Failed to move/copy reply thumb: {$file['thumb_path']} -> $target_thumb_path");
                            }
                        }
                    }
                }
            }
            // Insert reply
            $newIDs[$reply['id']] = $newPostID = post($reply);
            error_log("mod_move: Posted reply with new ID=$newPostID");
        }

        modLog("Moved thread #{$postID} to " . sprintf($config['board_abbreviation'], $targetBoard) . " (#{$newID})", $originBoardURI);

        $target_dir = sprintf($config['board_path'], $targetBoard) . $config['dir']['res'] . $op['live_date_path'];
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0775, true);
            error_log("mod_move: Created directory $target_dir");
        }

        $target_dir = sprintf($config['board_path'], $targetBoard) . $config['dir']['img'] . $op['live_date_path'];
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0775, true);
            error_log("mod_move: Created directory $target_dir");
        }

        $target_dir = sprintf($config['board_path'], $targetBoard) . $config['dir']['thumb'] . $op['live_date_path'];
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0775, true);
            error_log("mod_move: Created directory $target_dir");
        }

        // Build new thread
        buildThread($newID);
        clean();
        buildIndex();
        Vichan\Functions\Theme\rebuild_themes('post', $targetBoard);

        $newboard = $board;
        error_log("mod_move: New board context set to " . print_r($newboard, true));

        openBoard($originBoardURI);

        if ($shadow) {
            $query = prepare('UPDATE ``posts`` SET `locked` = 1 WHERE `board` = :board AND `id` = :id');
            $query->bindValue(':board', $originBoardURI);
            $query->bindValue(':id', $postID, PDO::PARAM_INT);
            $query->execute() or error(db_error($query));
            error_log("mod_move: Locked original thread ID=$postID on $originBoardURI");
        } else {
            deletePost($postID);
            buildIndex();
            error_log("mod_move: Deleted original thread ID=$postID on $originBoardURI");
        }

        // Construct and log redirect URL
        openBoard($targetBoard);
        $newboard = $board; // Ensure $newboard reflects target board
        $board_path = rtrim(sprintf($config['board_path'], $targetBoard), '/');
        $res_dir = rtrim($config['dir']['res'], '/');
        $live_date_path = trim($op['live_date_path'], '/');
        $link = link_for($op, false, $newboard);
        $redirect_url = "/mod.php?/{$board_path}/{$res_dir}/{$live_date_path}/{$link}";
        if (isset($config['root'])) {
            $redirect_url = rtrim($config['root'], '/') . $redirect_url;
        }
        error_log("mod_move: Config board_path=" . sprintf($config['board_path'], $targetBoard) . ", res_dir={$config['dir']['res']}");
        error_log("mod_move: Redirect URL components: board_path=$board_path, res_dir=$res_dir, live_date_path=$live_date_path, link=$link");
        error_log("mod_move: Final redirect URL: $redirect_url");

        // Perform redirect
        header('Location: ' . $redirect_url, true, $config['redirect_http']);
        return;
    }

    $boards = listBoards();
    if (count($boards) <= 1) {
        error(_('Impossible to move thread; there is only one board.'));
    }

    $board_path = rtrim(sprintf($config['board_path'], $originBoardURI), '/');
    $security_token = make_secure_link_token("{$board_path}/move/{$postID}");
    error_log("mod_move: Displaying move thread page for postID=$postID, originBoardURI=$originBoardURI");

    mod_page(
        _('Move thread'),
        $config['file_mod_move'],
        [
            'post' => $postID,
            'board' => $originBoardURI,
            'boards' => $boards,
            'token' => $security_token
        ],
        $mod
    );
}

function mod_ban_post(Context $ctx, $board, $delete, $post, $token = false) {
    global $mod;
    $config = $ctx->get('config');

    // Extract the board URI (e.g., "b") from the full board path (e.g., "channel/b")
    $board_uri = basename($board);

    if (!openBoard($board_uri)) {
        error($config['error']['noboard']);
    }

    if (!hasPermission($config['mod']['ban'], $board_uri)) {
        error($config['error']['noaccess']);
    }

    $security_token = make_secure_link_token('channel/' . $board_uri . '/ban/' . $post);

    $query = prepare('SELECT ' . ($config['ban_show_post'] ? '*' : '`ip`, `thread`') .
    ' FROM ``posts`` WHERE `board` = :board AND `id` = :id');
	$query->bindValue(':board', $board_uri);
    $query->bindValue(':id', $post);
    $query->execute() or error(db_error($query));
    if (!$_post = $query->fetch(PDO::FETCH_ASSOC)) {
        error($config['error']['404']);
    }

    $thread = $_post['thread'];
    $ip = $_post['ip'];

    if (isset($_POST['new_ban'], $_POST['reason'], $_POST['length'], $_POST['board'])) {
        if (isset($_POST['ip'])) {
            $ip = $_POST['ip'];
        }

        Bans::new_ban($ip, $_POST['reason'], $_POST['length'], $_POST['board'] == '*' ? false : $_POST['board'],
            false, $config['ban_show_post'] ? $_post : false);

        if (isset($_POST['public_message'], $_POST['message'])) {
            // Public ban message
            $length_english = Bans::parse_time($_POST['length']) ? 'for ' . Format\until(Bans::parse_time($_POST['length'])) : 'permanently';
            $_POST['message'] = preg_replace('/[\r\n]/', '', $_POST['message']);
            $_POST['message'] = str_replace('%length%', $length_english, $_POST['message']);
            $_POST['message'] = str_replace('%LENGTH%', strtoupper($length_english), $_POST['message']);
            $query = prepare('UPDATE ``posts`` SET `body_nomarkup` = CONCAT(`body_nomarkup`, :body_nomarkup) WHERE `board` = :board AND `id` = :id');
			$query->bindValue(':board', $board_uri);
            $query->bindValue(':id', $post);
            $query->bindValue(':body_nomarkup', sprintf("\n<tinyboard ban message>%s</tinyboard>", utf8tohtml($_POST['message'])));
            $query->execute() or error(db_error($query));
            rebuildPost($post);

            modLog("Attached a public ban message to post #{$post}: " . utf8tohtml($_POST['message']));
            buildThread($thread ? $thread : $post);
            buildIndex();
        } elseif (isset($_POST['delete']) && (int)$_POST['delete']) {
            // Delete post
            deletePost($post);
            modLog("Deleted post #{$post}");
            // Rebuild board
            buildIndex();
            // Rebuild themes
            Vichan\Functions\Theme\rebuild_themes('post-delete', $board_uri);
        }

        header('Location: ?/' . sprintf($config['board_path'], $board_uri) . $config['file_index'], true, $config['redirect_http']);
    }

    $args = [
        'ip' => $ip,
        'hide_ip' => !hasPermission($config['mod']['show_ip'], $board_uri),
        'post' => $post,
        'board' => $board_uri,
        'delete' => (bool)$delete,
        'boards' => listBoards(),
        'reasons' => $config['premade_ban_reasons'],
        'token' => $security_token
    ];

    mod_page(_('New ban'), $config['file_mod_ban_form'], $args, $mod);
}

function mod_edit_post(Context $ctx, $board, $edit_raw_html, $postID) {
    global $mod;
    $config = $ctx->get('config');

    // Extract the board URI (e.g., "b") from the full board path (e.g., "channel/b")
    $board_uri = basename($board);

    if (!openBoard($board_uri)) {
        error($config['error']['noboard']);
    }

    if (!hasPermission($config['mod']['editpost'], $board_uri)) {
        error($config['error']['noaccess']);
    }

    if ($edit_raw_html && !hasPermission($config['mod']['rawhtml'], $board_uri)) {
        error($config['error']['noaccess']);
    }

    // Generate the security token
    $board_path = rtrim(sprintf($config['board_path'], $board_uri), '/');
	$security_token = make_secure_link_token("{$board_path}/edit" . ($edit_raw_html ? '_raw' : '') . "/{$postID}");



    $query = prepare('SELECT *, live_date_path FROM ``posts`` WHERE `board` = :board AND `id` = :id');
	$query->bindValue(':board', $board_uri);
	$query->bindValue(':id', $postID);
	$query->execute() or error(db_error($query));

	if (!$post = $query->fetch(PDO::FETCH_ASSOC)) {
		error($config['error']['404']);
	}

    if (isset($_POST['name'], $_POST['email'], $_POST['subject'], $_POST['body'])) {
        // Validate the security token
        if (!isset($_POST['token']) || $_POST['token'] !== $security_token) {
            error('Invalid security token! Please go back and try again.');
        }

        // Process the form submission
        $_POST['body'] = remove_modifiers($_POST['body']);
        $modifiers = extract_modifiers($post['body_nomarkup']);
        foreach ($modifiers as $key => $value) {
            $_POST['body'] .= "<tinyboard $key>$value</tinyboard>";
        }

        if ($edit_raw_html) {
			$query = prepare('UPDATE ``posts`` SET `name` = :name, `email` = :email, `subject` = :subject, `body` = :body, `body_nomarkup` = :body_nomarkup WHERE `board` = :board AND `id` = :id');
		} else {
			$query = prepare('UPDATE ``posts`` SET `name` = :name, `email` = :email, `subject` = :subject, `body_nomarkup` = :body WHERE `board` = :board AND `id` = :id');
		}
		$query->bindValue(':board', $board_uri);
        $query->bindValue(':id', $postID);
        $query->bindValue(':name', $_POST['name']);
        $query->bindValue(':email', $_POST['email']);
        $query->bindValue(':subject', $_POST['subject']);
        $query->bindValue(':body', $_POST['body']);
        if ($edit_raw_html) {
            $body_nomarkup = $_POST['body'] . "\n<tinyboard raw html>1</tinyboard>";
            $query->bindValue(':body_nomarkup', $body_nomarkup);
        }
        $query->execute() or error(db_error($query));

        modLog("Edited post #{$postID}");
        rebuildPost($postID);
        buildIndex();

        Vichan\Functions\Theme\rebuild_themes('post', $board_uri);

        header('Location: ?/' . sprintf($config['board_path'], $board_uri) . $config['dir']['res'] . $post['live_date_path'] . '/' . link_for($post) . '#' . $postID, true, $config['redirect_http']);
    } else {
        // Remove modifiers
        $post['body_nomarkup'] = remove_modifiers($post['body_nomarkup']);
        $post['body_nomarkup'] = utf8tohtml($post['body_nomarkup']);
        $post['body'] = utf8tohtml($post['body']);

        mod_page(
            _('Edit post'),
            $config['file_mod_edit_post_form'],
            [
                'token' => $security_token,
                'board' => $board_uri,
                'raw' => $edit_raw_html,
                'post' => $post
            ],
            $mod
        );
    }
}

function mod_delete(Context $ctx, $board, $post) {
    $config = $ctx->get('config');

    // Extract the board URI (e.g., "b") from the full board path (e.g., "channel/b")
    $board_uri = basename($board);

    if (!openBoard($board_uri)) {
        error($config['error']['noboard']);
    }

    if (!hasPermission($config['mod']['delete'], $board_uri)) {
        error($config['error']['noaccess']);
    }

    // Delete post
    deletePost($post);
    // Record the action
    modLog("Deleted post #{$post}");
    // Rebuild board
    buildIndex();
    // Rebuild themes
    Vichan\Functions\Theme\rebuild_themes('post-delete', $board_uri);

    // Redirect to the board index
    header('Location: ?/' . sprintf($config['board_path'], $board_uri) . $config['file_index'], true, $config['redirect_http']);
}

function mod_deletefile(Context $ctx, $board, $post, $file) {
    $config = $ctx->get('config');

    // Extract the board URI (e.g., "3e") from the full board path (e.g., "channel/3e")
    $board_uri = basename($board);

    if (!openBoard($board_uri)) {
        error($config['error']['noboard']);
    }

    if (!hasPermission($config['mod']['deletefile'], $board_uri)) {
        error($config['error']['noaccess']);
    }

    // Delete file
    deleteFile($post, true, $file);
    // Record the action
    modLog("Deleted file from post #{$post}");

    // Rebuild board
    buildIndex();
    // Rebuild themes
    Vichan\Functions\Theme\rebuild_themes('post-delete', $board_uri);

    // Redirect
    header('Location: ?/' . sprintf($config['board_path'], $board_uri) . $config['file_index'], true, $config['redirect_http']);
}

function mod_spoiler_image(Context $ctx, $board, $post, $file) {
    $config = $ctx->get('config');

    // Extract the board URI (e.g., "3e") from the full board path (e.g., "channel/3e")
    $board_uri = basename($board);

    if (!openBoard($board_uri)) {
        error($config['error']['noboard']);
    }

    if (!hasPermission($config['mod']['spoilerimage'], $board_uri)) {
        error($config['error']['noaccess']);
    }

    // Fetch files, thread, and live_date_path
    $query = prepare("SELECT `files`, `thread`, `live_date_path` FROM ``posts`` WHERE `board` = :board AND id = :id");
    $query->bindValue(':board', $board_uri);
    $query->bindValue(':id', $post, PDO::PARAM_INT);
    $query->execute() or error(db_error($query));
    $result = $query->fetch(PDO::FETCH_ASSOC);
    $files = json_decode($result['files']);

    $size_spoiler_image = @getimagesize($config['spoiler_image']);

    // Remove the old thumbnail using live_date_path
    if (!empty($files[$file]->thumb) && $files[$file]->thumb !== 'spoiler' && $files[$file]->thumb !== 'deleted') {
        $thumb_path = $board_uri . '/' . $config['dir']['thumb'] . $result['live_date_path'] . '/' . $files[$file]->thumb;
        file_unlink($thumb_path);
    }

    $files[$file]->thumb = 'spoiler';
    $files[$file]->thumbwidth = $size_spoiler_image[0];
    $files[$file]->thumbheight = $size_spoiler_image[1];

    // Make thumbnail spoiler
    $query = prepare("UPDATE ``posts`` SET `files` = :files WHERE `board` = :board AND `id` = :id");
    $query->bindValue(':board', $board_uri);
    $query->bindValue(':files', json_encode($files));
    $query->bindValue(':id', $post, PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    // Record the action
    modLog("Spoilered file from post #{$post}");

    // Rebuild thread
    buildThread($result['thread'] ? $result['thread'] : $post);

    // Rebuild board
    buildIndex();

    // Rebuild themes
    Vichan\Functions\Theme\rebuild_themes('post-delete', $board_uri);

    // Redirect
    header('Location: ?/' . sprintf($config['board_path'], $board_uri) . $config['file_index'], true, $config['redirect_http']);
}

function mod_deletebyip(Context $ctx, $boardName, $post, $global = false) {
    global $board;
    $config = $ctx->get('config');

    $global = (bool)$global;

    // Extract the board URI (e.g., "b") from the full board path (e.g., "channel/b")
    $board_uri = basename($boardName);

    if (!openBoard($board_uri)) {
        error($config['error']['noboard']);
    }

    if (!$global && !hasPermission($config['mod']['deletebyip'], $board_uri)) {
        error($config['error']['noaccess']);
    }

    if ($global && !hasPermission($config['mod']['deletebyip_global'], $board_uri)) {
        error($config['error']['noaccess']);
    }

    // Find IP address
    $query = prepare('SELECT `ip` FROM ``posts`` WHERE `board` = :board AND `id` = :id');
	$query->bindValue(':board', $board_uri);
    $query->bindValue(':id', $post);
    $query->execute() or error(db_error($query));
    if (!$ip = $query->fetchColumn()) {
        error($config['error']['invalidpost']);
    }

    $boards = $global ? listBoards() : array(array('uri' => $board_uri));

    $query = '';
    foreach ($boards as $_board) {
		$query = prepare('SELECT `thread`, `id`, `board` FROM ``posts`` WHERE `board` = :board AND `ip` = :ip');
		$query->bindValue(':board', $_board['uri']);
		$query->bindValue(':ip', $ip);
		$query->execute() or error(db_error($query));
		// ... process results ...
	}

    if ($query->rowCount() < 1) {
        error($config['error']['invalidpost']);
    }

    @set_time_limit($config['mod']['rebuild_timelimit']);

    $threads_to_rebuild = [];
    $threads_deleted = [];
    while ($post = $query->fetch(PDO::FETCH_ASSOC)) {
        openBoard($post['board']);

        deletePost($post['id'], false, false);

        Vichan\Functions\Theme\rebuild_themes('post-delete', $board['uri']);

        buildIndex();

        if ($post['thread']) {
            $threads_to_rebuild[$post['board']][$post['thread']] = true;
        } else {
            $threads_deleted[$post['board']][$post['id']] = true;
        }
    }

    foreach ($threads_to_rebuild as $_board => $_threads) {
        openBoard($_board);
        foreach ($_threads as $_thread => $_dummy) {
            if ($_dummy && !isset($threads_deleted[$_board][$_thread])) {
                buildThread($_thread);
            }
        }
        buildIndex();
    }

    if ($global) {
        $board = false;
    }

    // Record the action
    $cip = cloak_ip($ip);
    modLog("Deleted all posts by IP address: <a href=\"?/IP/$cip\">$cip</a>");

    // Redirect
    header('Location: ?/' . sprintf($config['board_path'], $board_uri) . $config['file_index'], true, $config['redirect_http']);
}

function mod_user(Context $ctx, $uid) {
	global $mod;
	$config = $ctx->get('config');

	if (!hasPermission($config['mod']['editusers']) && !(hasPermission($config['mod']['change_password']) && $uid == $mod['id']))
		error($config['error']['noaccess']);

	$query = prepare('SELECT * FROM ``mods`` WHERE `id` = :id');
	$query->bindValue(':id', $uid);
	$query->execute() or error(db_error($query));
	if (!$user = $query->fetch(PDO::FETCH_ASSOC))
		error($config['error']['404']);

	if (hasPermission($config['mod']['editusers']) && isset($_POST['username'], $_POST['password'])) {
		if (isset($_POST['allboards'])) {
			$boards = array('*');
		} else {
			$_boards = listBoards();
			foreach ($_boards as &$board) {
				$board = $board['uri'];
			}

			$boards = [];
			foreach ($_POST as $name => $value) {
				if (preg_match('/^board_(' . $config['board_regex'] . ')$/u', $name, $matches) && in_array($matches[1], $_boards))
					$boards[] = $matches[1];
			}
		}

		if (isset($_POST['delete'])) {
			if (!hasPermission($config['mod']['deleteusers']))
				error($config['error']['noaccess']);

			$query = prepare('DELETE FROM ``mods`` WHERE `id` = :id');
			$query->bindValue(':id', $uid);
			$query->execute() or error(db_error($query));

			modLog('Deleted user ' . utf8tohtml($user['username']) . ' <small>(#' . $user['id'] . ')</small>');

			header('Location: ?/users', true, $config['redirect_http']);

			return;
		}

		if ($_POST['username'] == '')
			error(sprintf($config['error']['required'], 'username'));

		$query = prepare('UPDATE ``mods`` SET `username` = :username, `boards` = :boards WHERE `id` = :id');
		$query->bindValue(':id', $uid);
		$query->bindValue(':username', $_POST['username']);
		$query->bindValue(':boards', implode(',', $boards));
		$query->execute() or error(db_error($query));

		if ($user['username'] !== $_POST['username']) {
			// account was renamed
			modLog('Renamed user "' . utf8tohtml($user['username']) . '" <small>(#' . $user['id'] . ')</small> to "' . utf8tohtml($_POST['username']) . '"');
		}

		if ($_POST['password'] != '') {
			list($version, $password) = crypt_password($_POST['password']);

			$query = prepare('UPDATE ``mods`` SET `password` = :password, `version` = :version WHERE `id` = :id');
			$query->bindValue(':id', $uid);
			$query->bindValue(':password', $password);
			$query->bindValue(':version', $version);
			$query->execute() or error(db_error($query));

			modLog('Changed password for ' . utf8tohtml($_POST['username']) . ' <small>(#' . $user['id'] . ')</small>');

			if ($uid == $mod['id']) {
				login($_POST['username'], $_POST['password']);
				setCookies();
			}
		}

		if (hasPermission($config['mod']['manageusers']))
			header('Location: ?/users', true, $config['redirect_http']);
		else
			header('Location: ?/', true, $config['redirect_http']);

		return;
	}

	if (hasPermission($config['mod']['change_password']) && $uid == $mod['id'] && isset($_POST['password'])) {
		if ($_POST['password'] != '') {
			list($version, $password) = crypt_password($_POST['password']);

			$query = prepare('UPDATE ``mods`` SET `password` = :password, `version` = :version WHERE `id` = :id');
			$query->bindValue(':id', $uid);
			$query->bindValue(':password', $password);
			$query->bindValue(':version', $version);
			$query->execute() or error(db_error($query));

			modLog('Changed own password');

			login($user['username'], $_POST['password']);
			setCookies();
		}

		if (hasPermission($config['mod']['manageusers']))
			header('Location: ?/users', true, $config['redirect_http']);
		else
			header('Location: ?/', true, $config['redirect_http']);

		return;
	}

	if (hasPermission($config['mod']['modlog'])) {
		$query = prepare('SELECT * FROM ``modlogs`` WHERE `mod` = :id ORDER BY `time` DESC LIMIT 5');
		$query->bindValue(':id', $uid);
		$query->execute() or error(db_error($query));
		$log = $query->fetchAll(PDO::FETCH_ASSOC);
	} else {
		$log = [];
	}

	$user['boards'] = explode(',', $user['boards']);

	mod_page(
		_('Edit user'),
		$config['file_mod_user'],
		[
			'user' => $user,
			'logs' => $log,
			'boards' => listBoards(),
			'token' => make_secure_link_token('users/' . $user['id'])
		],
		$mod
	);
}

function mod_user_new(Context $ctx) {
	global $pdo, $config, $mod;

	if (!hasPermission($config['mod']['createusers']))
		error($config['error']['noaccess']);

	if (isset($_POST['username'], $_POST['password'], $_POST['type'])) {
		if ($_POST['username'] == '')
			error(sprintf($config['error']['required'], 'username'));
		if ($_POST['password'] == '')
			error(sprintf($config['error']['required'], 'password'));

		if (isset($_POST['allboards'])) {
			$boards = array('*');
		} else {
			$_boards = listBoards();
			foreach ($_boards as &$board) {
				$board = $board['uri'];
			}

			$boards = [];
			foreach ($_POST as $name => $value) {
				if (preg_match('/^board_(' . $config['board_regex'] . ')$/u', $name, $matches) && in_array($matches[1], $_boards))
					$boards[] = $matches[1];
			}
		}

		$type = (int)$_POST['type'];
		if (!isset($config['mod']['groups'][$type]) || $type == DISABLED)
			error(sprintf($config['error']['invalidfield'], 'type'));

		list($version, $password) = crypt_password($_POST['password']);

		$query = prepare('INSERT INTO ``mods`` VALUES (NULL, :username, :password, :version, :type, :boards)');
		$query->bindValue(':username', $_POST['username']);
		$query->bindValue(':password', $password);
		$query->bindValue(':version', $version);
		$query->bindValue(':type', $type);
		$query->bindValue(':boards', implode(',', $boards));
		$query->execute() or error(db_error($query));

		$userID = $pdo->lastInsertId();

		modLog('Created a new user: ' . utf8tohtml($_POST['username']) . ' <small>(#' . $userID . ')</small>');

		header('Location: ?/users', true, $config['redirect_http']);
		return;
	}

	mod_page(
		_('New user'),
		$config['file_mod_user'],
		[
			'new' => true,
			'boards' => listBoards(),
			'token' => make_secure_link_token('users/new')
		],
		$mod
	);
}


function mod_users(Context $ctx) {
	global $mod;
	$config = $ctx->get('config');

	if (!hasPermission($config['mod']['manageusers']))
		error($config['error']['noaccess']);

	// Pagination params
	$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 25;
	$last_time = isset($_GET['last_time']) && is_numeric($_GET['last_time']) ? (int)$_GET['last_time'] : null;
	$last_type = isset($_GET['last_type']) && is_numeric($_GET['last_type']) ? (int)$_GET['last_type'] : null;

	// Total user count
	$total_query = query("SELECT COUNT(*) FROM ``mods``") or error(db_error());
	$total_users = (int)$total_query->fetchColumn();

	// Main query
	$sql = "
		SELECT
			m.*,
			(SELECT ml.`time` FROM ``modlogs`` ml WHERE ml.`mod` = m.`id` ORDER BY ml.`time` DESC LIMIT 1) AS `last`,
			(SELECT ml.`text` FROM ``modlogs`` ml WHERE ml.`mod` = m.`id` ORDER BY ml.`time` DESC LIMIT 1) AS `action`
		FROM ``mods`` m
		LEFT JOIN (
			SELECT `mod`, MAX(`time`) AS last FROM ``modlogs`` GROUP BY `mod`
		) ml ON ml.mod = m.id
		WHERE (
			(:last_time IS NULL AND :last_type IS NULL)
			OR (m.`type` < :last_type OR (m.`type` = :last_type AND ml.last < :last_time))
		)
		ORDER BY m.`type` DESC, ml.last DESC
		LIMIT :limit
	";

	$query = prepare($sql);
	$query->bindValue(':last_time', $last_time, PDO::PARAM_INT);
	$query->bindValue(':last_type', $last_type, PDO::PARAM_INT);
	$query->bindValue(':limit', $limit, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$users = $query->fetchAll(PDO::FETCH_ASSOC);

	// Add secure action tokens
	foreach ($users as &$user) {
		$user['promote_token'] = make_secure_link_token("users/{$user['id']}/promote");
		$user['demote_token'] = make_secure_link_token("users/{$user['id']}/demote");
	}

	// Update cursor for next page
	$new_last_time = null;
	$new_last_type = null;
	if (!empty($users)) {
		$last_user = end($users);
		$new_last_time = $last_user['last'] ?? 0;
		$new_last_type = $last_user['type'];
	}

	// Render page
	mod_page(
		sprintf('%s (%d)', _('Manage users'), $total_users),
		$config['file_mod_users'],
		[
			'users' => $users,
			'limit' => $limit,
			'last_time' => $new_last_time,
			'last_type' => $new_last_type
		],
		$mod
	);
}


function mod_user_promote(Context $ctx, $uid, $action) {
	$config = $ctx->get('config');

	if (!hasPermission($config['mod']['promoteusers']))
		error($config['error']['noaccess']);

	$query = prepare("SELECT `type`, `username` FROM ``mods`` WHERE `id` = :id");
	$query->bindValue(':id', $uid);
	$query->execute() or error(db_error($query));

	if (!$mod = $query->fetch(PDO::FETCH_ASSOC))
		error($config['error']['404']);

	$new_group = false;

	$groups = $config['mod']['groups'];
	if ($action == 'demote')
		$groups = array_reverse($groups, true);

	foreach ($groups as $group_value => $group_name) {
		if ($action == 'promote' && $group_value > $mod['type']) {
			$new_group = $group_value;
			break;
		} elseif ($action == 'demote' && $group_value < $mod['type']) {
			$new_group = $group_value;
			break;
		}
	}

	if ($new_group === false || $new_group == DISABLED)
		error(_('Impossible to promote/demote user.'));

	$query = prepare("UPDATE ``mods`` SET `type` = :group_value WHERE `id` = :id");
	$query->bindValue(':id', $uid);
	$query->bindValue(':group_value', $new_group);
	$query->execute() or error(db_error($query));

	modLog(($action == 'promote' ? 'Promoted' : 'Demoted') . ' user "' .
		utf8tohtml($mod['username']) . '" to ' . $config['mod']['groups'][$new_group]);

	header('Location: ?/users', true, $config['redirect_http']);
}

function mod_pm(Context $ctx, $id, $reply = false) {
	global $mod, $config;

	if ($reply && !hasPermission($config['mod']['create_pm']))
		error($config['error']['noaccess']);

	$query = prepare("SELECT ``mods``.`username`, `mods_to`.`username` AS `to_username`, ``pms``.* FROM ``pms`` LEFT JOIN ``mods`` ON ``mods``.`id` = `sender` LEFT JOIN ``mods`` AS `mods_to` ON `mods_to`.`id` = `to` WHERE ``pms``.`id` = :id");
	$query->bindValue(':id', $id);
	$query->execute() or error(db_error($query));

	if ((!$pm = $query->fetch(PDO::FETCH_ASSOC)) || ($pm['to'] != $mod['id'] && !hasPermission($config['mod']['master_pm'])))
		error($config['error']['404']);

	if (isset($_POST['delete'])) {
		$query = prepare("DELETE FROM ``pms`` WHERE `id` = :id");
		$query->bindValue(':id', $id);
		$query->execute() or error(db_error($query));

		$cache = $ctx->get(CacheDriver::class);
		$cache->delete('pm_unread_' . $mod['id']);
		$cache->delete('pm_unreadcount_' . $mod['id']);

		header('Location: ?/', true, $config['redirect_http']);
		return;
	}

	if ($pm['unread'] && $pm['to'] == $mod['id']) {
		$query = prepare("UPDATE ``pms`` SET `unread` = 0 WHERE `id` = :id");
		$query->bindValue(':id', $id);
		$query->execute() or error(db_error($query));

		$cache = $ctx->get(CacheDriver::class);
		$cache->delete('pm_unread_' . $mod['id']);
		$cache->delete('pm_unreadcount_' . $mod['id']);

		modLog('Read a PM');
	}

	if ($reply) {
		if (!$pm['to_username'])
			error($config['error']['404']); // deleted?

		mod_page(
			sprintf('%s %s', _('New PM for'), $pm['to_username']),
			$config['file_mod_new_pm'],
			[
				'username' => $pm['username'],
				'id' => $pm['sender'],
				'message' => quote($pm['message']),
				'token' => make_secure_link_token('new_PM/' . $pm['username'])
			],
			$mod
		);
	} else {
		mod_page(sprintf('%s &ndash; #%d', _('Private message'), $id), $config['file_mod_pm'], $pm, $mod);
	}
}

function mod_inbox(Context $ctx, $page_no = 1) {
	global $mod;
	$config = $ctx->get('config');

	// Number of messages per page (set this in your config if you want)
	$per_page = isset($config['mod']['inbox_page']) ? $config['mod']['inbox_page'] : 20;
	$page_no = (int)$page_no;
	if ($page_no < 1) $page_no = 1;
	$offset = ($page_no - 1) * $per_page;

	// Fetch paginated messages
	$query = prepare('SELECT `unread`, ``pms``.`id`, `time`, `sender`, `to`, `message`, `username`
		FROM ``pms``
		LEFT JOIN ``mods`` ON ``mods``.`id` = `sender`
		WHERE `to` = :mod
		ORDER BY `unread` DESC, `time` DESC
		LIMIT :offset, :limit');
	$query->bindValue(':mod', $mod['id'], PDO::PARAM_INT);
	$query->bindValue(':offset', $offset, PDO::PARAM_INT);
	$query->bindValue(':limit', $per_page, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$messages = $query->fetchAll(PDO::FETCH_ASSOC);

	// Count total messages for pagination
	$query = prepare('SELECT COUNT(*) FROM ``pms`` WHERE `to` = :mod');
	$query->bindValue(':mod', $mod['id'], PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$total = $query->fetchColumn();

	// Count unread messages
	$query = prepare('SELECT COUNT(*) FROM ``pms`` WHERE `to` = :mod AND `unread` = 1');
	$query->bindValue(':mod', $mod['id'], PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$unread = $query->fetchColumn();

	foreach ($messages as &$message) {
		$message['snippet'] = pm_snippet($message['message']);
	}

	mod_page(
		sprintf('%s (%s)', _('PM inbox'), $total > 0 ? $unread . ' unread' : 'empty'),
		$config['file_mod_inbox'],
		[
			'messages' => $messages,
			'unread' => $unread,
			'count' => $total,
			'page_no' => $page_no,
			'per_page' => $per_page
		],
		$mod
	);
}


function mod_new_pm(Context $ctx, $username) {
	global $mod;
	$config = $ctx->get('config');

	if (!hasPermission($config['mod']['create_pm']))
		error($config['error']['noaccess']);

	$query = prepare("SELECT `id` FROM ``mods`` WHERE `username` = :username");
	$query->bindValue(':username', $username);
	$query->execute() or error(db_error($query));
	if (!$id = $query->fetchColumn()) {
		// Old style ?/PM: by user ID
		$query = prepare("SELECT `username` FROM ``mods`` WHERE `id` = :username");
		$query->bindValue(':username', $username);
		$query->execute() or error(db_error($query));
		if ($username = $query->fetchColumn())
			header('Location: ?/new_PM/' . $username, true, $config['redirect_http']);
		else
			error($config['error']['404']);
	}

	if (isset($_POST['message'])) {
		$_POST['message'] = escape_markup_modifiers($_POST['message']);
		markup($_POST['message']);

		$query = prepare("INSERT INTO ``pms`` VALUES (NULL, :me, :id, :message, :time, 1)");
		$query->bindValue(':me', $mod['id']);
		$query->bindValue(':id', $id);
		$query->bindValue(':message', $_POST['message']);
		$query->bindValue(':time', time());
		$query->execute() or error(db_error($query));

		$cache = $ctx->get(CacheDriver::class);

		$cache->delete('pm_unread_' . $id);
		$cache->delete('pm_unreadcount_' . $id);

		modLog('Sent a PM to ' . utf8tohtml($username));

		header('Location: ?/', true, $config['redirect_http']);
	}

	mod_page(
		sprintf('%s %s', _('New PM for'), $username),
		$config['file_mod_new_pm'],
		[
			'username' => $username,
			'id' => $id,
			'token' => make_secure_link_token('new_PM/' . $username)
		],
		$mod
	);
}

function mod_rebuild(Context $ctx) {
    global $twig, $mod;
    $config = $ctx->get('config');
    $cache = $ctx->get(CacheDriver::class);

    if (!hasPermission($config['mod']['rebuild']))
        error($config['error']['noaccess']);

    session_start();

    // Helper: batch rebuild archive index pages for a board
    $rebuild_archive_batches = function($board_uri, &$log, $batch_size) {
        $start_page = 1;
        do {
            $next_page = Archive::buildArchiveIndexBatch($board_uri, $start_page, $batch_size);
            $log[] = "Rebuilt archive index pages $start_page to " . ($next_page ? $next_page - 1 : $start_page + $batch_size - 1) . " for board $board_uri";
            $start_page = $next_page;
        } while ($start_page);
    };

    // Handle cancel request
    if (isset($_POST['cancel'])) {
        if (!isset($_POST['token']) || !make_secure_link_token($_POST['token'], 'rebuild')) {
            error($config['error']['invalidtoken']);
        }
        unset($_SESSION['rebuild_progress'], $_SESSION['rebuild_options']);
        $log = ['Rebuild cancelled.'];
        mod_page(_('Rebuild cancelled'), $config['file_mod_rebuilt'], ['logs' => $log], $mod);
        return;
    }

    // Start new rebuild session
    if (isset($_POST['rebuild'])) {
        if (!isset($_POST['token']) || !make_secure_link_token($_POST['token'], 'rebuild')) {
            error($config['error']['invalidtoken']);
        }

        $_SESSION['rebuild_progress'] = [
            'boards' => [],
            'current_board' => null,
            'threads' => [],
            'thread_index' => 0,
            'step' => 0,
            'replies' => [],
            'reply_index' => 0,
            'current_thread' => null,
            'index_batch' => 0,
            'archive_batch' => 0,
            'archive_boards' => [],
            'catalog_batch' => 0 // <-- Add catalog batch progress
        ];

        foreach (listBoards() as $board) {
            if (isset($_POST['boards_all']) || isset($_POST['board_' . $board['uri']])) {
                $_SESSION['rebuild_progress']['boards'][] = $board['uri'];
            }
        }

        // Populate archive boards if archive rebuilding is enabled
        if (isset($_POST['rebuild_archive']) && $config['archive']['threads']) {
            $query = query("SELECT DISTINCT `board_uri` FROM `archive_threads`");
            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $_SESSION['rebuild_progress']['archive_boards'][] = $row['board_uri'];
            }
        }

        $_SESSION['rebuild_options'] = [
            'rebuild_cache' => isset($_POST['rebuild_cache']),
            'rebuild_javascript' => isset($_POST['rebuild_javascript']),
            'rebuild_index' => isset($_POST['rebuild_index']),
            'rebuild_threads' => isset($_POST['rebuild_threads']),
            'rebuild_posts' => isset($_POST['rebuild_posts']),
            'rebuild_themes' => isset($_POST['rebuild_themes']),
            'rebuild_archive' => isset($_POST['rebuild_archive']),
            'rebuild_catalog' => isset($_POST['rebuild_catalog']) // <-- Add catalog option
        ];

        header('Location: ?/rebuild');
        exit;
    }

    $log = [];

    // Handle rebuild progress
    if (isset($_SESSION['rebuild_progress'])) {
        $progress = &$_SESSION['rebuild_progress'];
        $options = $_SESSION['rebuild_options'];
        $batch_size = isset($config['rebuild_batch_size']) ? max(1, (int)$config['rebuild_batch_size']) : 10;

        // Global rebuild tasks
        if (empty($progress['global_tasks_done'])) {
            $progress['global_tasks_done'] = true;

            if ($options['rebuild_cache']) {
                if ($config['cache']['enabled']) {
                    $log[] = 'Clearing system cache';
                    $cache->flush();
                }
                load_twig();
                $twig->getCache()->clear();
            }

            if ($options['rebuild_javascript']) {
                $log[] = 'Regenerating JavaScript file <strong>' . $config['file_script'] . '</strong>';
                buildJavascript();
            }

            if ($options['rebuild_themes']) {
                $log[] = 'Regenerating all theme files';
                Vichan\Functions\Theme\rebuild_themes('all');
            }
        }

        // Check if all tasks are complete
        $archive_done = !isset($progress['archive_boards']) ||
            $progress['archive_batch'] * $batch_size >= count($progress['archive_boards']);
        $catalog_done = !isset($options['rebuild_catalog']) || !$options['rebuild_catalog'] ||
            $progress['catalog_batch'] === false;
        if (
            $progress['step'] >= count($progress['boards']) &&
            empty($progress['threads']) &&
            empty($progress['replies']) &&
            $archive_done &&
            $catalog_done
        ) {
            unset($_SESSION['rebuild_progress'], $_SESSION['rebuild_options']);
            $log[] = 'All rebuild tasks completed successfully.';
            mod_page(_('Rebuild complete'), $config['file_mod_rebuilt'], ['logs' => $log], $mod);
            return;
        }

        // Move to next board if no threads/replies
        if (empty($progress['threads']) && empty($progress['replies']) && $progress['step'] < count($progress['boards'])) {
            $board_uri = $progress['boards'][$progress['step']];
            $progress['current_board'] = $board_uri;
            openBoard($board_uri);
            $config['try_smarter'] = false;

            $log[] = '<strong>' . sprintf($config['board_abbreviation'], $board_uri) . '</strong>: Starting board rebuild…';

            if ($options['rebuild_cache']) {
                if ($config['cache']['enabled']) {
                    $log[] = 'Clearing board cache';
                    $cache->flush();
                }
                load_twig();
                $twig->getCache()->clear();
                $options['rebuild_cache'] = false;
            }

            if ($options['rebuild_themes']) {
                $log[] = 'Regenerating theme files for board';
                Vichan\Functions\Theme\rebuild_themes('all');
                $options['rebuild_themes'] = false;
            }

            if ($options['rebuild_javascript']) {
                $log[] = 'Regenerating JavaScript file <strong>' . $config['file_script'] . '</strong> for board';
                buildJavascript();
                $options['rebuild_javascript'] = false;
            }

            if ($options['rebuild_index']) {
                $start_page = $progress['index_batch'] * $batch_size + 1;
                $end_page = min(($progress['index_batch'] + 1) * $batch_size, $config['max_pages']);
                $log[] = "Rebuilding index pages $start_page to $end_page for board $board_uri";
                buildIndex($start_page, $end_page);
                $progress['index_batch']++;

                if ($progress['index_batch'] * $batch_size >= $config['max_pages']) {
                    $progress['index_batch'] = 0;
                    $options['rebuild_index'] = false;
                } else {
                    mod_page(_('Rebuild in progress…'), $config['file_mod_rebuilt'], [
                        'logs' => $log,
                        'in_progress' => true,
                        'token' => make_secure_link_token('rebuild')
                    ], $mod);
                    header("Refresh: 1; URL=?/rebuild");
                    exit;
                }
            }

            // --- Catalog theme batch rebuild ---
            if ($options['rebuild_catalog']) {
				require_once $config['dir']['themes'] . '/catalog/theme.php';

				// Load theme settings for catalog, and set the board
				$settings = Vichan\Functions\Theme\theme_settings('catalog');
				$settings['boards'] = $board_uri;

				$catalog = new Catalog();

				// Get total threads to determine total pages
				$sql = "SELECT COUNT(*) FROM `posts` WHERE `board` = :board AND `thread` IS NULL";
				$query = prepare($sql);
				$query->bindValue(':board', $board_uri);
				$query->execute() or error(db_error($query));
				$total_threads = $query->fetchColumn();
				$per_page = isset($settings['items_per_page']) ? (int)$settings['items_per_page'] : 50;
				$total_pages = $per_page > 0 ? (int)ceil($total_threads / $per_page) : 1;

				$catalog_batch = $progress['catalog_batch'] ?? 0;
				$start_page = $catalog_batch * $batch_size + 1;
				$end_page = min(($catalog_batch + 1) * $batch_size, $total_pages);

				if ($start_page <= $total_pages) {
					$log[] = "Rebuilding catalog pages $start_page to $end_page for board $board_uri";
					$catalog->build($settings, $board_uri, false, $start_page, $end_page);
					$progress['catalog_batch'] = $catalog_batch + 1;

					if ($end_page < $total_pages) {
						mod_page(_('Rebuild in progress…'), $config['file_mod_rebuilt'], [
							'logs' => $log,
							'in_progress' => true,
							'token' => make_secure_link_token('rebuild')
						], $mod);
						header("Refresh: 1; URL=?/rebuild");
						exit;
					} else {
						$progress['catalog_batch'] = 0;
						$options['rebuild_catalog'] = false;
					}
				} else {
					$progress['catalog_batch'] = 0;
					$options['rebuild_catalog'] = false;
				}
			}

            $log[] = 'Fetching threads for board ' . $board_uri;
            $query = prepare("SELECT `id` FROM ``posts`` WHERE `board` = :board AND `thread` IS NULL");
            $query->bindValue(':board', $board_uri);
            $query->execute() or error(db_error($query));
            $progress['threads'] = [];
            while ($post = $query->fetch(PDO::FETCH_ASSOC)) {
                $progress['threads'][] = $post['id'];
            }
            $progress['thread_index'] = 0;
            $progress['replies'] = [];
            $progress['reply_index'] = 0;
            $progress['current_thread'] = null;

            // Archive batch rebuild for this board
            if ($options['rebuild_archive'] && class_exists('Archive')) {
                $log[] = "Rebuilding all archive index pages for board $board_uri in batches of $batch_size...";
                $rebuild_archive_batches($board_uri, $log, $batch_size);
            }
        }

        // Process threads and replies
        if (!empty($progress['threads']) || !empty($progress['replies'])) {
            $board_uri = $progress['current_board'];
            openBoard($board_uri);

            // Process replies
            while ($options['rebuild_posts'] && !empty($progress['replies'])) {
                $replies_to_process = array_slice($progress['replies'], $progress['reply_index'], $batch_size);
                foreach ($replies_to_process as $reply_id) {
                    $log[] = 'Rebuilding reply #' . $reply_id . ' in board ' . $board_uri;
                    rebuildPost($reply_id);
                }
                $progress['reply_index'] += $batch_size;

                if ($progress['reply_index'] >= count($progress['replies'])) {
                    $progress['replies'] = [];
                    $progress['reply_index'] = 0;
                    $progress['current_thread'] = null;
                    $progress['thread_index']++;
                } else {
                    mod_page(_('Rebuild in progress…'), $config['file_mod_rebuilt'], [
                        'logs' => $log,
                        'in_progress' => true,
                        'token' => make_secure_link_token('rebuild')
                    ], $mod);
                    header("Refresh: 1; URL=?/rebuild");
                    exit;
                }
            }

            // Process threads
            if (!empty($progress['threads'])) {
                $threads_to_process = array_slice($progress['threads'], $progress['thread_index'], $batch_size);
                foreach ($threads_to_process as $thread_id) {
                    if ($options['rebuild_threads']) {
                        $log[] = 'Rebuilding thread #' . $thread_id . ' in board ' . $board_uri;
                        buildThread($thread_id);
                    }

                    if ($options['rebuild_posts']) {
                        $query = prepare("SELECT `id` FROM ``posts`` WHERE `board` = :board AND `thread` = :thread");
                        $query->bindValue(':board', $board_uri);
                        $query->bindValue(':thread', $thread_id);
                        $query->execute() or error(db_error($query));
                        $progress['replies'] = [];
                        while ($reply = $query->fetch(PDO::FETCH_ASSOC)) {
                            $progress['replies'][] = $reply['id'];
                        }
                        $progress['reply_index'] = 0;
                        $progress['current_thread'] = $thread_id;

                        if (!empty($progress['replies'])) {
                            break;
                        }
                    }
                    $progress['thread_index']++;
                }

                if ($progress['thread_index'] >= count($progress['threads']) && empty($progress['replies'])) {
                    $progress['threads'] = [];
                    $progress['thread_index'] = 0;

                    // Archive batch rebuild for this board
                    if ($options['rebuild_archive'] && class_exists('Archive')) {
                        $log[] = "Rebuilding all archive index pages for board $board_uri in batches of $batch_size...";
                        $rebuild_archive_batches($board_uri, $log, $batch_size);
                    }

                    $progress['step']++;
                }
            }

            mod_page(_('Rebuild in progress…'), $config['file_mod_rebuilt'], [
                'logs' => $log,
                'in_progress' => true,
                'token' => make_secure_link_token('rebuild')
            ], $mod);

            header("Refresh: 1; URL=?/rebuild");
            exit;
        }

        // Process archive boards in batches (global archive rebuild)
        if ($options['rebuild_archive'] && !empty($progress['archive_boards']) && $progress['step'] >= count($progress['boards'])) {
            $start_index = $progress['archive_batch'] * $batch_size;
            $boards_to_process = array_slice($progress['archive_boards'], $start_index, $batch_size);

            if (!empty($boards_to_process)) {
                $log[] = 'Rebuilding archive indexes for boards: ' . implode(', ', $boards_to_process);
                Archive::RebuildArchiveIndexes($boards_to_process);
                $progress['archive_batch']++;

                mod_page(_('Rebuild in progress…'), $config['file_mod_rebuilt'], [
                    'logs' => $log,
                    'in_progress' => true,
                    'token' => make_secure_link_token('rebuild')
                ], $mod);
                header("Refresh: 1; URL=?/rebuild");
                exit;
            }
        }
    }

    // Initial rebuild form
    mod_page(_('Rebuild'), $config['file_mod_rebuild'], [
        'boards' => listBoards(),
        'token' => make_secure_link_token('rebuild')
    ], $mod);
}

function mod_reports(Context $ctx, $page_no = 1) {
	global $mod;
	$config = $ctx->get('config');

	if (!hasPermission($config['mod']['reports']))
		error($config['error']['noaccess']);

	$page_no = (int)$page_no;
	if ($page_no < 1) $page_no = 1;
	$per_page = isset($config['mod']['reports_page']) ? (int)$config['mod']['reports_page'] : 25;
	$offset = ($page_no - 1) * $per_page;

	// Get total report count
	$query = query("SELECT COUNT(*) FROM ``reports``") or error(db_error());
	$total_reports = (int)$query->fetchColumn();

	// Fetch paginated reports
	$query = prepare("SELECT * FROM ``reports`` ORDER BY `time` DESC, `id` DESC LIMIT :offset, :limit");
	$query->bindValue(':offset', $offset, PDO::PARAM_INT);
	$query->bindValue(':limit', $per_page, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$reports = $query->fetchAll(PDO::FETCH_ASSOC);

	$report_queries = [];
	foreach ($reports as $report) {
		if (!isset($report_queries[$report['board']]))
			$report_queries[$report['board']] = [];
		$report_queries[$report['board']][] = $report['post'];
	}

	$report_posts = [];
	foreach ($report_queries as $board => $posts) {
		$report_posts[$board] = [];
		$query = prepare('SELECT * FROM ``posts`` WHERE `board` = :board AND (`id` = ' . implode(' OR `id` = ', $posts) . ')');
		$query->bindValue(':board', $board);
		$query->execute() or error(db_error($query));
		while ($post = $query->fetch(PDO::FETCH_ASSOC)) {
			$report_posts[$board][$post['id']] = $post;
		}
	}

	$body = '';
	foreach ($reports as $report) {
		if (!isset($report_posts[$report['board']][$report['post']])) {
			$query = prepare("DELETE FROM ``reports`` WHERE `post` = :id AND `board` = :board");
			$query->bindValue(':id', $report['post'], PDO::PARAM_INT);
			$query->bindValue(':board', $report['board']);
			$query->execute() or error(db_error($query));
			continue;
		}

		openBoard($report['board']);

		$post = &$report_posts[$report['board']][$report['post']];

		if (!$post['thread']) {
			$po = new Thread($post, '?/', $mod, false);
		} else {
			$po = new Post($post, '?/', $mod);
		}

		$append_html = Element($config['file_mod_report'], array(
			'report' => $report,
			'config' => $config,
			'mod' => $mod,
			'pm' => create_pm_header(),
			'token' => make_secure_link_token('reports/' . $report['id'] . '/dismiss'),
			'token_all' => make_secure_link_token('reports/' . $report['id'] . '/dismiss&all'),
			'token_post' => make_secure_link_token('reports/'. $report['id'] . '/dismiss&post'),
		));

		$po->body = truncate($po->body, $po->link(), $config['body_truncate'] - substr_count($append_html, '<br>'));

		if (mb_strlen($po->body) + mb_strlen($append_html) > $config['body_truncate_char']) {
			$__old_body_truncate_char = $config['body_truncate_char'];
			$config['body_truncate_char'] = mb_strlen($po->body) + mb_strlen($append_html);
		}

		$po->body .= $append_html;
		$body .= $po->build(true) . '<hr>';

		if (isset($__old_body_truncate_char))
			$config['body_truncate_char'] = $__old_body_truncate_char;
	}

	$total_pages = ceil($total_reports / $per_page);

	mod_page(
		sprintf('%s (%d)', _('Report queue'), $total_reports),
		$config['file_mod_reports'],
		[
			'reports' => $body,
			'count' => $total_reports,
			'page_no' => $page_no,
			'per_page' => $per_page,
			'total_pages' => $total_pages
		],
		$mod
	);
}




function mod_report_dismiss(Context $ctx, $id, $action) {
	$config = $ctx->get('config');

	$query = prepare("SELECT `post`, `board`, `ip` FROM ``reports`` WHERE `id` = :id");
	$query->bindValue(':id', $id);
	$query->execute() or error(db_error($query));
	if ($report = $query->fetch(PDO::FETCH_ASSOC)) {
		$ip = $report['ip'];
		$board = $report['board'];
		$post = $report['post'];
	} else
		error($config['error']['404']);

	switch($action){
		case '&post':
			if (!hasPermission($config['mod']['report_dismiss_post'], $board))
				error($config['error']['noaccess']);

			$query = prepare("DELETE FROM ``reports`` WHERE `post` = :post");
			$query->bindValue(':post', $post);
			modLog("Dismissed all reports for post #{$id}", $board);
			break;
		case '&all':
			if (!hasPermission($config['mod']['report_dismiss_ip'], $board))
				error($config['error']['noaccess']);

			$query = prepare("DELETE FROM ``reports`` WHERE `ip` = :ip");
			$query->bindValue(':ip', $ip);
			$cip = cloak_ip($ip);
			modLog("Dismissed all reports by <a href=\"?/IP/$cip\">$cip</a>");
			break;
		case '':
		default:
			if (!hasPermission($config['mod']['report_dismiss'], $board))
				error($config['error']['noaccess']);

			$query = prepare("DELETE FROM ``reports`` WHERE `id` = :id");
			$query->bindValue(':id', $id);
			modLog("Dismissed a report for post #{$id}", $board);
			break;
	}
	$query->execute() or error(db_error($query));

	header('Location: ?/reports', true, $config['redirect_http']);
}

function mod_recent_posts(Context $ctx, $lim) {
	global $mod, $pdo;
	$config = $ctx->get('config');

	if (!hasPermission($config['mod']['recent']))
		error($config['error']['noaccess']);

	$limit = (is_numeric($lim))? $lim : 25;
	$last_time = (isset($_GET['last']) && is_numeric($_GET['last'])) ? $_GET['last'] : 0;

	$mod_boards = [];
	$boards = listBoards();

	//if not all boards
	if ($mod['boards'][0]!='*') {
		foreach ($boards as $board) {
			if (in_array($board['uri'], $mod['boards']))
				$mod_boards[] = $board;
		}
	} else {
		$mod_boards = $boards;
	}

	// Manually build an SQL query
	$query = prepare('SELECT * FROM ``posts`` WHERE `board` IN (' . implode(',', array_map([$pdo, 'quote'], array_column($mod_boards, 'uri'))) . ') AND (`time` < :last_time OR NOT :last_time) ORDER BY `time` DESC LIMIT ' . $limit);
	$query->bindValue(':last_time', $last_time);
	$query->execute() or error(db_error($query));
	$posts = $query->fetchAll(PDO::FETCH_ASSOC);

	foreach ($posts as &$post) {
		openBoard($post['board']);
		if (!$post['thread']) {
			// Still need to fix this:
			$po = new Thread($post, '?/', $mod, false);
			$post['built'] = $po->build(true);
		} else {
			$po = new Post($post, '?/', $mod);
			$post['built'] = $po->build(true);
		}
		$last_time = $post['time'];
	}

	echo mod_page(
		_('Recent posts'),
		$config['file_mod_recent_posts'],
		[
			'posts' => $posts,
			'limit' => $limit,
			'last_time' => $last_time
		],
		$mod
	);
}

function mod_config(Context $ctx, $board_config = false) {
	global $mod, $board;
	$config = $ctx->get('config');

	if ($board_config && !openBoard($board_config))
		error($config['error']['noboard']);

	else {
		include $board_config . '/config.php';
	}

	if (!hasPermission($config['mod']['edit_config'], $board_config))
		error($config['error']['noaccess']);

	$config_file = $board_config ? $board['dir'] . 'config.php' : 'inc/secrets.php';

	if ($config['mod']['config_editor_php']) {
		$readonly = !(is_file($config_file) ? is_writable($config_file) : is_writable(dirname($config_file)));

		if (!$readonly && isset($_POST['code'])) {
			$code = $_POST['code'];
			// Save previous instance_config if php_check_syntax fails
			$old_code = file_get_contents($config_file);
			file_put_contents($config_file, $code);
			$resp = shell_exec_error('php -l ' . $config_file);
			if (preg_match('/No syntax errors detected/', $resp)) {
				header('Location: ?/config' . ($board_config ? '/' . $board_config : ''), true, $config['redirect_http']);
				return;
			}
			else {
				file_put_contents($config_file, $old_code);
				error($config['error']['badsyntax'] . $resp);
			}
		}

		$instance_config = @file_get_contents($config_file);
		if ($instance_config === false) {
			$instance_config = "<?php\n\n// This file does not exist yet. You are creating it.";
		}
		$instance_config = str_replace("\n", '&#010;', utf8tohtml($instance_config));

		mod_page(
			_('Config editor'),
			$config['file_mod_config_editor_php'],
			[
				'php' => $instance_config,
				'readonly' => $readonly,
				'boards' => listBoards(),
				'board' => $board_config,
				'file' => $config_file,
				'token' => make_secure_link_token('config' . ($board_config ? '/' . $board_config : ''))
			],
			$mod
		);
		return;
	}

	require_once 'inc/mod/config-editor.php';

	$conf = config_vars();

	foreach ($conf as &$var) {
		if (is_array($var['name'])) {
			$c = &$config;
			foreach ($var['name'] as $n)
				$c = &$c[$n];
		} else {
			$c = @$config[$var['name']];
		}

		$var['value'] = $c;
	}
	unset($var);

	if (isset($_POST['save'])) {
		$config_append = '';

		foreach ($conf as $var) {
			$field_name = 'cf_' . (is_array($var['name']) ? implode('/', $var['name']) : $var['name']);

			if ($var['type'] == 'boolean')
				$value = isset($_POST[$field_name]);
			elseif (isset($_POST[$field_name]))
				$value = $_POST[$field_name];
			else
				continue; // ???

			if (!settype($value, $var['type']))
				continue; // invalid

			if ($value != $var['value']) {
				// This value has been changed.

				$config_append .= '$config';

				if (is_array($var['name'])) {
					foreach ($var['name'] as $name)
						$config_append .= '[' . var_export($name, true) . ']';
				} else {
					$config_append .= '[' . var_export($var['name'], true) . ']';
				}


				$config_append .= ' = ';
				if (@$var['permissions'] && isset($config['mod']['groups'][$value])) {
					$config_append .= $config['mod']['groups'][$value];
				} else {
					$config_append .= var_export($value, true);
				}
				$config_append .= ";\n";
			}
		}

		if (!empty($config_append)) {
			$config_append = "\n// Changes made via web editor by \"" . $mod['username'] . "\" @ " . date('r') . ":\n" . $config_append . "\n";
			if (!is_file($config_file))
				$config_append = "<?php\n\n$config_append";
			if (!@file_put_contents($config_file, $config_append, FILE_APPEND)) {
				$config_append = htmlentities($config_append);

				if ($config['minify_html'])
					$config_append = str_replace("\n", '&#010;', $config_append);
				$page = [];
				$page['title'] = 'Cannot write to file!';
				$page['config'] = $config;
				$page['body'] = '
					<p style="text-align:center">Tinyboard could not write to <strong>' . $config_file . '</strong> with the ammended configuration, probably due to a permissions error.</p>
					<p style="text-align:center">You may proceed with these changes manually by copying and pasting the following code to the end of <strong>' . $config_file . '</strong>:</p>
					<textarea style="width:700px;height:370px;margin:auto;display:block;background:white;color:black" readonly>' . $config_append . '</textarea>
				';
				$page['pm'] = create_pm_header();
				echo Element($config['file_page_template'], $page);
				exit;
			}
		}

		header('Location: ?/config' . ($board_config ? '/' . $board_config : ''), true, $config['redirect_http']);

		exit;
	}

	mod_page(
		_('Config editor') . ($board_config ? ': ' . sprintf($config['board_abbreviation'], $board_config) : ''),
		$config['file_mod_config_editor'],
		[
			'boards' => listBoards(),
			'board' => $board_config,
			'conf' => $conf,
			'file' => $config_file,
			'token' => make_secure_link_token('config' . ($board_config ? '/' . $board_config : ''))
		],
		$mod
	);
}

function mod_themes_list(Context $ctx) {
	global $mod;
	$config = $ctx->get('config');

	if (!hasPermission($config['mod']['themes']))
		error($config['error']['noaccess']);

	if (!is_dir($config['dir']['themes']))
		error(_('Themes directory doesn\'t exist!'));
	if (!$dir = opendir($config['dir']['themes']))
		error(_('Cannot open themes directory; check permissions.'));

	$query = query('SELECT `theme` FROM ``theme_settings`` WHERE `name` IS NULL AND `value` IS NULL') or error(db_error());
	$themes_in_use = $query->fetchAll(PDO::FETCH_COLUMN);

	// Scan directory for themes
	$themes = [];
	while ($file = readdir($dir)) {
		if ($file[0] != '.' && is_dir($config['dir']['themes'] . '/' . $file)) {
			$themes[$file] = Vichan\Functions\Theme\load_theme_config($file);
		}
	}
	closedir($dir);

	foreach ($themes as $theme_name => &$theme) {
		$theme['rebuild_token'] = make_secure_link_token('themes/' . $theme_name . '/rebuild');
		$theme['uninstall_token'] = make_secure_link_token('themes/' . $theme_name . '/uninstall');
	}

	mod_page(
		_('Manage themes'),
		$config['file_mod_themes'],
		[
			'themes' => $themes,
			'themes_in_use' => $themes_in_use,
		],
		$mod
	);
}

function mod_theme_configure(Context $ctx, $theme_name) {
	global $mod;
	$config = $ctx->get('config');

	if (!hasPermission($config['mod']['themes']))
		error($config['error']['noaccess']);

	if (!$theme = Vichan\Functions\Theme\load_theme_config($theme_name)) {
		error($config['error']['invalidtheme']);
	}

	$cache = $ctx->get(CacheDriver::class);

	if (isset($_POST['install'])) {
		// Check if everything is submitted
		foreach ($theme['config'] as &$conf) {
			if (!isset($_POST[$conf['name']]) && $conf['type'] != 'checkbox')
				error(sprintf($config['error']['required'], $c['title']));
		}

		// Clear previous settings
		$query = prepare("DELETE FROM ``theme_settings`` WHERE `theme` = :theme");
		$query->bindValue(':theme', $theme_name);
		$query->execute() or error(db_error($query));

		foreach ($theme['config'] as &$conf) {
			$query = prepare("INSERT INTO ``theme_settings`` VALUES(:theme, :name, :value)");
			$query->bindValue(':theme', $theme_name);
			$query->bindValue(':name', $conf['name']);
			if ($conf['type'] == 'checkbox')
				$query->bindValue(':value', isset($_POST[$conf['name']]) ? 1 : 0);
			else
				$query->bindValue(':value', $_POST[$conf['name']]);
			$query->execute() or error(db_error($query));
		}

		$query = prepare("INSERT INTO ``theme_settings`` VALUES(:theme, NULL, NULL)");
		$query->bindValue(':theme', $theme_name);
		$query->execute() or error(db_error($query));

		// Clean cache
		$cache->delete("themes");
		$cache->delete("theme_settings_$theme_name");

		$result = true;
		$message = false;
		if (isset($theme['install_callback'])) {
			$ret = $theme['install_callback'](Vichan\Functions\Theme\theme_settings($theme_name));
			if ($ret && !empty($ret)) {
				if (is_array($ret) && count($ret) == 2) {
					$result = $ret[0];
					$message = $ret[1];
				}
			}
		}

		if (!$result) {
			// Install failed
			$query = prepare("DELETE FROM ``theme_settings`` WHERE `theme` = :theme");
			$query->bindValue(':theme', $theme_name);
			$query->execute() or error(db_error($query));
		}

		// Build themes
		Vichan\Functions\Theme\rebuild_themes('all');

		mod_page(
			sprintf(_($result ? 'Installed theme: %s' : 'Installation failed: %s'), $theme['name']),
			$config['file_mod_theme_installed'],
			[
				'theme_name' => $theme_name,
				'theme' => $theme,
				'result' => $result,
				'message' => $message
			],
			$mod
		);
		return;
	}

	$settings = Vichan\Functions\Theme\theme_settings($theme_name);

	mod_page(
		sprintf(_('Configuring theme: %s'), $theme['name']),
		$config['file_mod_theme_config'],
		[
			'theme_name' => $theme_name,
			'theme' => $theme,
			'settings' => $settings,
			'token' => make_secure_link_token('themes/' . $theme_name)
		],
		$mod
	);
}

function mod_theme_uninstall(Context $ctx, $theme_name) {
	$config = $ctx->get('config');

	if (!hasPermission($config['mod']['themes']))
		error($config['error']['noaccess']);

	$cache = $ctx->get(CacheDriver::class);

	$query = prepare("DELETE FROM ``theme_settings`` WHERE `theme` = :theme");
	$query->bindValue(':theme', $theme_name);
	$query->execute() or error(db_error($query));

	// Clean cache
	$cache->delete("themes");
	$cache->delete("theme_settings_$theme_name");

	header('Location: ?/themes', true, $config['redirect_http']);
}

function mod_theme_rebuild(Context $ctx, $theme_name) {
	global $mod;
	$config = $ctx->get('config');

	if (!hasPermission($config['mod']['themes']))
		error($config['error']['noaccess']);

	Vichan\Functions\Theme\rebuild_theme($theme_name, 'all');

	mod_page(
		sprintf(_('Rebuilt theme: %s'), $theme_name),
		$config['file_mod_theme_rebuilt'],
		[
			'theme_name' => $theme_name,
		],
		$mod
	);
}

// This needs to be done for `secure` CSRF prevention compatibility, otherwise the $board will be read in as the token if editing global pages.
function delete_page_base(Context $ctx, $page = '', $board = false) {
	global $config, $mod;

	if (empty($board))
		$board = false;

	if (!$board && $mod['boards'][0] !== '*')
		error($config['error']['noaccess']);

	if (!hasPermission($config['mod']['edit_pages'], $board))
		error($config['error']['noaccess']);

	if ($board !== FALSE && !openBoard($board))
		error($config['error']['noboard']);

	if ($board) {
		$query = prepare('DELETE FROM ``pages`` WHERE `board` = :board AND `name` = :name');
		$query->bindValue(':board', ($board ? $board : NULL));
	} else {
		$query = prepare('DELETE FROM ``pages`` WHERE `board` IS NULL AND `name` = :name');
	}
	$query->bindValue(':name', $page);
	$query->execute() or error(db_error($query));

	header('Location: ?/edit_pages' . ($board ? ('/' . $board) : ''), true, $config['redirect_http']);
}

function mod_delete_page(Context $ctx, $page = '') {
	delete_page_base($ctx, $page);
}

function mod_delete_page_board(Context $ctx, $page = '', $board = false) {
	delete_page_base($ctx, $page, $board);
}

function mod_edit_page(Context $ctx, $id) {
	global $mod, $board;
	$config = $ctx->get('config');

	$query = prepare('SELECT * FROM ``pages`` WHERE `id` = :id');
	$query->bindValue(':id', $id);
	$query->execute() or error(db_error($query));
	$page = $query->fetch();

	if (!$page)
		error(_('Could not find the page you are trying to edit.'));

	if (!$page['board'] && $mod['boards'][0] !== '*')
		error($config['error']['noaccess']);

	if (!hasPermission($config['mod']['edit_pages'], $page['board']))
		error($config['error']['noaccess']);

	if ($page['board'] && !openBoard($page['board']))
		error($config['error']['noboard']);

	if (isset($_POST['method'], $_POST['content'])) {
		$content = $_POST['content'];
		$method = $_POST['method'];
		$page['type'] = $method;

		if (!in_array($method, array('markdown', 'html', 'infinity')))
			error(_('Unrecognized page markup method.'));

		switch ($method) {
			case 'markdown':
				$write = markdown($content);
				break;
			case 'html':
				if (hasPermission($config['mod']['rawhtml'])) {
					$write = $content;
				} else {
					$write = purify_html($content);
				}
				break;
			case 'infinity':
				$c = $content;
				markup($content);
				$write = $content;
				$content = $c;
		}

		if (!isset($write) or !$write)
			error(_('Failed to mark up your input for some reason...'));

		$query = prepare('UPDATE ``pages`` SET `type` = :method, `content` = :content WHERE `id` = :id');
		$query->bindValue(':method', $method);
		$query->bindValue(':content', $content);
		$query->bindValue(':id', $id);
		$query->execute() or error(db_error($query));

		$fn = (isset($board['uri']) ? ($board['uri'] . '/') : '') . $page['name'] . '.html';
		$body = "<div class='ban'>$write</div>";
		$html = Element($config['file_page_template'], [
			'config' => $config,
			'boardlist' => createBoardlist(),
			'body' => $body,
			'title' => utf8tohtml($page['title']),
			'pm' => create_pm_header()
		]);
		file_write($fn, $html);
	}

	if (!isset($content)) {
		$query = prepare('SELECT `content` FROM ``pages`` WHERE `id` = :id');
		$query->bindValue(':id', $id);
		$query->execute() or error(db_error($query));
		$content = $query->fetchColumn();
	}

	mod_page(
		sprintf(_('Editing static page: %s'), $page['name']),
		$config['file_mod_edit_page'],
		[
			'page' => $page,
			'token' => make_secure_link_token("edit_page/$id"),
			'content' => prettify_textarea($content),
			'board' => $board
		],
		$mod
	);
}

function mod_pages(Context $ctx, $board = false) {
	global $mod, $pdo;
	$config = $ctx->get('config');

	if (empty($board))
		$board = false;

	if (!$board && $mod['boards'][0] !== '*')
		error($config['error']['noaccess']);

	if (!hasPermission($config['mod']['edit_pages'], $board))
		error($config['error']['noaccess']);

	if ($board !== FALSE && !openBoard($board))
		error($config['error']['noboard']);

	if ($board) {
		$query = prepare('SELECT * FROM ``pages`` WHERE `board` = :board');
		$query->bindValue(':board', $board);
	} else {
		$query = query('SELECT * FROM ``pages`` WHERE `board` IS NULL');
	}
	$query->execute() or error(db_error($query));
	$pages = $query->fetchAll(PDO::FETCH_ASSOC);

	if (isset($_POST['page'])) {
		if ($board and sizeof($pages) > $config['pages_max'])
			error(sprintf(_('Sorry, this site only allows %d pages per board.'), $config['pages_max']));

		if (!preg_match('/^[a-z0-9]{1,255}$/', $_POST['page']))
			error(_('Page names must be < 255 chars and may only contain lowercase letters A-Z and digits 1-9.'));

		foreach ($pages as $i => $p) {
			if ($_POST['page'] === $p['name'])
				error(_('Refusing to create a new page with the same name as an existing one.'));
		}

		$title = ($_POST['title'] ? $_POST['title'] : NULL);

		$query = prepare('INSERT INTO ``pages``(board, title, name) VALUES(:board, :title, :name)');
		$query->bindValue(':board', ($board ? $board : NULL));
		$query->bindValue(':title', $title);
		$query->bindValue(':name', $_POST['page']);
		$query->execute() or error(db_error($query));

		$pages[] = array('id' => $pdo->lastInsertId(), 'name' => $_POST['page'], 'board' => $board, 'title' => $title);
	}

	foreach ($pages as $i => &$p) {
		$p['delete_token'] = make_secure_link_token('edit_pages/delete/' . $p['name'] . ($board ? ('/' . $board) : ''));
	}

	mod_page(
		_('Pages'),
		$config['file_mod_pages'],
		[
			'pages' => $pages,
			'token' => make_secure_link_token('edit_pages' . ($board ? ('/' . $board) : '')),
			'board' => $board
		],
		$mod
	);
}

function mod_debug_antispam(Context $ctx) {
	global $pdo, $config, $mod;

	$args = [];

	if (isset($_POST['board'], $_POST['thread'])) {
		$where = '`board` = ' . $pdo->quote($_POST['board']);
		if ($_POST['thread'] != '')
			$where .= ' AND `thread` = ' . $pdo->quote($_POST['thread']);

		if (isset($_POST['purge'])) {
			$query = prepare(', DATE ``antispam`` SET `expires` = UNIX_TIMESTAMP() + :expires WHERE' . $where);
			$query->bindValue(':expires', $config['spam']['hidden_inputs_expire']);
			$query->execute() or error(db_error());
		}

		$args['board'] = $_POST['board'];
		$args['thread'] = $_POST['thread'];
	} else {
		$where = '';
	}

	$query = query('SELECT COUNT(*) FROM ``antispam``' . ($where ? " WHERE $where" : '')) or error(db_error());
	$args['total'] = number_format($query->fetchColumn());

	$query = query('SELECT COUNT(*) FROM ``antispam`` WHERE `expires` IS NOT NULL' . ($where ? " AND $where" : '')) or error(db_error());
	$args['expiring'] = number_format($query->fetchColumn());

	$query = query('SELECT * FROM ``antispam`` ' . ($where ? "WHERE $where" : '') . ' ORDER BY `passed` DESC LIMIT 40') or error(db_error());
	$args['top'] = $query->fetchAll(PDO::FETCH_ASSOC);

	$query = query('SELECT * FROM ``antispam`` ' . ($where ? "WHERE $where" : '') . ' ORDER BY `created` DESC LIMIT 20') or error(db_error());
	$args['recent'] = $query->fetchAll(PDO::FETCH_ASSOC);

	mod_page(_('Debug: Anti-spam'), $config['file_mod_debug_antispam'], $args, $mod);
}

function mod_debug_recent_posts(Context $ctx) {
	global $pdo, $config, $mod;

	$limit = 500;

	$boards = listBoards();

	// Manually build an SQL query
	$query = prepare('SELECT * FROM ``posts`` WHERE `board` IN (' . implode(',', array_map([$pdo, 'quote'], array_column($boards, 'uri'))) . ') ORDER BY `time` DESC LIMIT ' . $limit);
	$query->execute() or error(db_error($query));
	$posts = $query->fetchAll(PDO::FETCH_ASSOC);

	// Fetch recent posts from flood prevention cache
	$query = query("SELECT * FROM ``flood`` ORDER BY `time` DESC") or error(db_error());
	$flood_posts = $query->fetchAll(PDO::FETCH_ASSOC);

	foreach ($posts as &$post) {
		$post['snippet'] = pm_snippet($post['body']);
		foreach ($flood_posts as $flood_post) {
			if ($flood_post['time'] == $post['time'] &&
				$flood_post['posthash'] == make_comment_hex($post['body_nomarkup']) &&
				$flood_post['filehash'] == $post['filehash'])
				$post['in_flood_table'] = true;
		}
	}

	mod_page(_('Debug: Recent posts'), $config['file_mod_debug_recent_posts'], [ 'posts' => $posts, 'flood_posts' => $flood_posts ], $mod);
}

function mod_debug_sql(Context $ctx) {
	global $mod;
	$config = $ctx->get('config');

	if (!hasPermission($config['mod']['debug_sql']))
		error($config['error']['noaccess']);

	$args['security_token'] = make_secure_link_token('debug/sql');

	if (isset($_POST['query'])) {
		$args['query'] = $_POST['query'];
		if ($query = query($_POST['query'])) {
			$args['result'] = $query->fetchAll(PDO::FETCH_ASSOC);
			if (!empty($args['result']))
				$args['keys'] = array_keys($args['result'][0]);
			else
				$args['result'] = 'empty';
		} else {
			$args['error'] = db_error();
		}
	}

	mod_page(_('Debug: SQL'), $config['file_mod_debug_sql'], $args, $mod);
}

function mod_view_archive(Context $context, $boardName, $page_no = 1) {
    global $board, $config, $mod;

    if (!$config['archive']['threads']) return;
    if (!openBoard($boardName)) error($config['error']['noboard']);

    // --- Handle POST actions ---
    if (isset($_POST['token']) && make_secure_link_token($_POST['token'], $board['prefix'] . $board['uri'] . '/archive/')) {
        $redirect_page = isset($_POST['current_page']) && is_numeric($_POST['current_page']) ? (int)$_POST['current_page'] : 1;
        if (isset($_POST['feature'], $_POST['id'])) {
            if (!hasPermission($config['mod']['feature_archived_threads'], $board['uri'])) error($config['error']['noaccess']);
            Archive::featureThread($_POST['id'], $board['uri']);
            header('Location: ?/' . $board['dir'] . $config['dir']['archive'] . ($redirect_page > 1 ? 'pagination/1/' . $redirect_page . '.html' : ''), true, $config['redirect_http']);
            exit;
        } elseif (isset($_POST['mod_archive'], $_POST['id'])) {
            if (!hasPermission($config['mod']['add_to_mod_archive'], $board['uri'])) error($config['error']['noaccess']);
            Archive::featureThread($_POST['id'], $board['uri'], true);
            header('Location: ?/' . $board['dir'] . $config['dir']['archive'] . ($redirect_page > 1 ? 'pagination/1/' . $redirect_page . '.html' : ''), true, $config['redirect_http']);
            exit;
        } elseif (isset($_POST['delete'], $_POST['id'])) {
            if (!hasPermission($config['mod']['delete_archived_threads'], $board['uri'])) error($config['error']['noaccess']);
            Archive::deleteArchived($_POST['id'], $board['uri']);
            header('Location: ?/' . $board['dir'] . $config['dir']['archive'] . ($redirect_page > 1 ? 'pagination/1/' . $redirect_page . '.html' : ''), true, $config['redirect_http']);
            exit;
        }
    }

    $threads_per_page = isset($config['archive']['threads_per_page']) ? $config['archive']['threads_per_page'] : 5;
    $archive_items = Archive::getArchiveListPaginated($board['uri'], $page_no, $threads_per_page);
    $total_threads = Archive::getArchiveCount($board['uri']);
    $total_pages = ceil($total_threads / $threads_per_page);

    foreach ($archive_items as &$thread) {
        $thread['archived_url'] = $config['root'] . $board['dir'] . $config['dir']['archive'] . $config['dir']['res'] . $thread['path'] . '/' . sprintf($config['file_page'], $thread['original_thread_id']);
        $thread['image_url'] = $thread['first_image']
            ? $config['root'] . $board['dir'] . $config['dir']['archive'] . $config['dir']['thumb'] . $thread['path'] . '/' . $thread['first_image']
            : null;
        $thread['display_id'] = $thread['original_thread_id'];
    }

    mod_page(
        sprintf(_('Archived') . ' %s: ' . $config['board_abbreviation'], _('threads'), $board['uri']),
        'mod/archive_list.html',
        [
            'archive' => $archive_items,
            'thread_count' => $total_threads,
            'board' => $board,
            'current_page' => $page_no,
            'total_pages' => $total_pages,
            'mod' => $mod,
            'token' => make_secure_link_token($board['prefix'] . $board['uri'] . '/archive/')
        ],
        true
    );
}

function mod_view_archive_featured(Context $context, $boardName) {
    global $board, $config, $mod;

    if (!$config['feature']['threads']) return;
    if (!openBoard($boardName)) error($config['error']['noboard']);

    if (isset($_POST['token']) && make_secure_link_token($_POST['token'], $board['prefix'] . $board['uri'] . '/featured/')) {
        if (isset($_POST['delete'], $_POST['id'])) {
            if (!hasPermission($config['mod']['delete_featured_archived_threads'], $board['uri'])) error($config['error']['noaccess']);
            Archive::deleteFeatured($_POST['id'], $board['uri']);
        }
    }

    $archive_items = Archive::getArchiveList($board['uri'], true, false, true);

    foreach ($archive_items as &$thread) {
        $thread['featured_url'] = $config['root'] . $board['dir'] . $config['dir']['featured'] . $config['dir']['res'] . $thread['path'] . '/' . sprintf($config['file_page'], $thread['original_thread_id']);
        $thread['image_url'] = $thread['first_image']
            ? $config['root'] . $board['dir'] . $config['dir']['featured'] . $config['dir']['thumb'] . $thread['path'] . '/' . $thread['first_image']
            : null;
        $thread['display_id'] = $thread['original_thread_id'];
    }

    mod_page(
        sprintf(_('Featured') . ' %s: ' . $config['board_abbreviation'], _('threads'), $board['uri']),
        'mod/archive_featured_list.html',
        [
            'archive' => $archive_items,
            'board' => $board,
            'mod' => $mod,
            'token' => make_secure_link_token($board['prefix'] . $board['uri'] . '/featured/')
        ],
        true
    );
}

function mod_view_archive_mod_archive(Context $context, $boardName) {
    global $board, $config, $mod;

    if (!$config['mod_archive']['threads']) return;
    if (!hasPermission($config['mod']['view_mod_archive'], $board['uri'])) error($config['error']['noaccess']);
    if (!openBoard($boardName)) error($config['error']['noboard']);

    if (isset($_POST['token']) && make_secure_link_token($_POST['token'], $board['prefix'] . $board['uri'] . '/mod_archive/')) {
        if (isset($_POST['delete'], $_POST['id'])) {
            if (!hasPermission($config['mod']['remove_from_mod_archive'], $board['uri'])) error($config['error']['noaccess']);
            Archive::deleteFeatured($_POST['id'], $board['uri'], true);
        }
    }

    $archive_items = Archive::getArchiveList($board['uri'], false, true, true);

    foreach ($archive_items as &$thread) {
        $thread['featured_url'] = $config['root'] . $board['dir'] . $config['dir']['mod_archive'] . $config['dir']['res'] . $thread['path'] . '/' . sprintf($config['file_page'], $thread['original_thread_id']);
        $thread['image_url'] = $thread['first_image']
            ? $config['root'] . $board['dir'] . $config['dir']['mod_archive'] . $config['dir']['thumb'] . $thread['path'] . '/' . $thread['first_image']
            : null;
        $thread['display_id'] = $thread['original_thread_id'];
    }

    mod_page(
        sprintf(_('Mod Archive') . ' %s: ' . $config['board_abbreviation'], _('threads'), $board['uri']),
        'mod/archive_featured_list.html',
        [
            'archive' => $archive_items,
            'is_mod_archive' => true,
            'board' => $board,
            'mod' => $mod,
            'token' => make_secure_link_token($board['prefix'] . $board['uri'] . '/mod_archive/')
        ],
        true
    );
}

function mod_archive_thread(Context $ctx, $board_path_segment, $post_id) {
    global $config, $board;

    $board_uri = basename($board_path_segment);

    if (!openBoard($board_uri)) error($config['error']['noboard']);
    if (!hasPermission($config['mod']['send_threads_to_archive'], $board['uri'])) error($config['error']['noaccess']);

    Archive::archiveThread($post_id);
    mod_delete($ctx, $board_uri, $post_id);

    header('Location: ?/' . sprintf($config['board_path'], $board['uri']) . $config['file_index'], true, $config['redirect_http']);
}