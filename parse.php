<?php
	$json = file_get_contents('php://input');

	if (!$json) {
		telegram_log('Warning','Webhook URL','Endpoint was called without input.');
		return;
	}

	$data = (array)json_decode($json, TRUE);

    if ( $data['message']['message_id'] && ( $data['message']['message_id'] == get_option('wp_telegram_last_id') ) ) {
        telegram_log('EXCEPTION', 'MESSAGE_ID', json_encode($json, TRUE));
        die();
    }
    update_option('wp_telegram_last_id', $data['message']['message_id'] );

	if ( $data['message']['chat']['type'] == 'private' ) {
		$USERID = $data['message']['from']['id'];
		$CPT = 'telegram_subscribers';
		$PRIVATE = true; $GROUP = false;
		$COMMAND = $data['message']['text'];
	} else if ( $data['message']['chat']['type'] == 'group' || $data['message']['chat']['type'] == 'supergroup' ) {
		$USERID = $data['message']['chat']['id'];
		$CPT = 'telegram_groups';
		$GROUP = true; $PRIVATE = false;
		$COMMAND = $data['message']['text'];
	} else if ( $data['my_chat_member']['chat']['type'] == 'supergroup' ) {
		$USERID = $data['my_chat_member']['chat']['id'];
		$CPT = 'telegram_groups';
		$GROUP = true; $PRIVATE = false;
		$COMMAND = '';
	} else if ( $data['callback_query']['message']['text'] ) {
		$USERID = $data['callback_query']['message']['chat']['id'];
		$CPT = 'telegram_subscribers';
		$PRIVATE = true; $GROUP = false;
		$COMMAND = $data['callback_query']['data'];
	} else {
		telegram_log('EXCEPTION', 'CHAT TYPE', json_encode($json, TRUE));
		die();
	}

	telegram_log('>>>>', $USERID, $COMMAND);

    if (defined('WP_DEBUG') && true === WP_DEBUG) {
	   telegram_log('####', 'DEBUG', json_encode($json, TRUE));
    }

	if ( !telegram_getid( $USERID ) ) {
		$p = wp_insert_post(array(
			'post_title' => $USERID,
			'post_content' => '',
			'post_type' => $CPT,
			'post_status' => 'publish',
			'post_author' => 1,
		));
		if ( $PRIVATE ) {
			update_post_meta($p, 'telegram_first_name', $data['message']['from']['first_name']);
			update_post_meta($p, 'telegram_last_name', $data['message']['from']['last_name']);
			update_post_meta($p, 'telegram_username', $data['message']['from']['username']);
			telegram_sendmessage( $USERID, telegram_option('wmuser') );
		} else if ( $GROUP ) {
			$name = '';
			if ( isset( $data['message']['chat']['title'] ) ) {
				$name = $data['message']['chat']['title'];
			} else if ( isset( $data['my_chat_member']['chat']['title'] ) ) {
				$name = $data['my_chat_member']['chat']['title'];
			}
			update_post_meta($p, 'telegram_name', $name );
			telegram_log('', '', 'Bot added to <strong>'.$data['message']['chat']['title'].'</strong>');
            telegram_sendmessage( $USERID, telegram_option('wmgroup') );
		}
		return;
	} else if ($PRIVATE) {
		$POST_ID = telegram_getid( $USERID );
		$fieldsToCheck = [ 'first_name', 'last_name', 'username' ];
		foreach( $fieldsToCheck as $field ) {
			if ( get_post_meta( $POST_ID, 'telegram_'.$field, true ) != $data['message']['from'][$field] ) {
				update_post_meta( $POST_ID, 'telegram_'.$field, $data['message']['from'][$field]);
			}
		}
		$counter = get_post_meta($POST_ID, 'telegram_counter', true);
		$counter = $counter ? ++$counter : 1;
		update_post_meta( $POST_ID, 'telegram_counter', $counter );
	} else if ($GROUP) {
		update_post_meta($o->ID, 'telegram_name', 'xx');
	}

    if ( isset( $data['message']['location'] ) ) {
        $page = get_page_by_title( 'telegram-location', '', 'telegram_commands' );
        update_post_meta( telegram_getid( $USERID ), 'telegram_last_latitude', $data['message']['location']['latitude']);
        update_post_meta( telegram_getid( $USERID ), 'telegram_last_longitude', $data['message']['location']['longitude']);
        telegram_sendmessage( $USERID, $page->ID );
        do_action( 'telegram_parse_location', $USERID, $data['message']['location']['latitude'], $data['message']['location']['longitude']);
        return;
    } else if ( isset( $data['message']['photo'] ) ) {
	    	$imgcaption = date("F j, Y, g:i a") . ' from: @' . $data['message']['from']['username'] . ' ' . $data['message']['from']['first_name'] . ' ' . $data['message']['caption'];
		do_action( 'telegram_parse_photo', $USERID, $data['message']['photo'], $imgcaption );
		return;
	} else if ( isset( $data['message']['document'] ) ) {
		if( $data['message']['document']['mime_type'] == 'image/jpeg' ) {
			$imgcaption = date("F j, Y, g:i a") . ' from: @' . $data['message']['from']['username'] . ' ' . $data['message']['from']['first_name'] . ' ' . $data['message']['caption'];
			do_action( 'telegram_parse_photo', $USERID, $data['message']['document'], $imgcaption );	
		} 
		else 
			do_action( 'telegram_parse_document', $USERID, $data['message']['document'] );
		return;
	} else if ( isset( $data['message']['audio'] ) ) {
                do_action( 'telegram_parse_audio', $USERID, $data['message']['audio'] );
                return;
        } else if ( isset( $data['message']['contact'] ) ) {
                do_action( 'telegram_parse_contact', $USERID, $data['message']['contact'] );
                return;
        } else if ( isset( $data['message']['sticker'] ) ) {
                do_action( 'telegram_parse_sticker', $USERID, $data['message']['sticker'] );
                return;
        } else if ( isset( $data['message']['poll'] ) ) {
                do_action( 'telegram_parse_poll', $USERID, $data['message']['poll'] );
                return;
        }

    do_action( 'telegram_parse', $USERID, $COMMAND ); //EXPERIMENTAL

    $ok_found = false;
   	if ( !empty($COMMAND) ) {
    	
		global $wpdb, $q_config;
		$hunacc_chrs = array('á', 'é', 'í', 'ó', 'ő', 'ú', 'ű', 'ü', 'ö');
		$nonacc_chrs = array('a', 'e', 'i', 'o', 'o', 'u', 'u', 'u', 'o');

    	$lowermessage = strtolower(trim($COMMAND));
	$lowermessageelements = preg_split('/\s*[!\"\',.:;?`]\s*/', $lowermessage, -1, PREG_SPLIT_NO_EMPTY);
    	
	$sql = 'SELECT ID,post_title FROM wp_posts WHERE post_type = "telegram_commands" AND post_status = "publish" AND post_title LIKE "%' . implode('%" OR post_title LIKE "%', $lowermessageelements) .'%"';
        $res = $wpdb->get_results($sql);       	

	 if ( $data['message']['from']['language_code'] == 'hu'){
		$q_config['language'] = 'hu';
		$lowermessage = implode(',', $lowermessageelements);
	        $lowermessageelements = explode(',', str_replace($hunacc_chrs, $nonacc_chrs, $lowermessage));
	    }
	 
	    foreach($res as $reskey => $reselement) {
	        $lowertitle = strtolower(qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage($reselement->post_title));
	        if(substr($lowertitle, 0, 10) == '(english) ')
	            $lowertitle=substr($lowertitle, 10);
		    $lowertitle = explode(',', $lowertitle);
		    if ( $data['message']['from']['language_code'] == 'hu')
			    $lowertitle = str_replace($hunacc_chrs, $nonacc_chrs, $lowertitle);
	
         if( empty(array_intersect ($lowermessageelements, $lowertitle)) ){
						unset($res[$reskey]);
            }
		}
			
        if(count($res) >= 1) {
            $ok_found = true;
            foreach($res as $reselement) {
                $postid = (int) $reselement->ID;
                if($postid) {
				    if ( has_post_thumbnail( $postid ) ) {
                        $image = wp_get_attachment_image_src( get_post_thumbnail_id( $postid ), 'medium', true );
                        telegram_sendphoto( $USERID, $postid, $image[0] );
          	        }
                    else {
                        telegram_sendmessage( $USERID, $postid );
                    } 
			    }
            }
		}
		}
		
	if ( $PRIVATE ) {
		switch ($data['message']['text']) {
			case '/stop':
                $ok_found = true;
                telegram_sendmessage( $USERID, telegram_option('bmuser') );
                wp_delete_post( telegram_getid( $USERID ) );
			    break;
			default:
			    break;
			return;
		}
	}

	if ( $GROUP && $data['message']['left_chat_participant']['id'] == current( explode(':', telegram_option('token') ) ) ) {
        wp_delete_post( telegram_getid( $USERID ) );
		telegram_log('', '', 'Bot removed from <strong>'.$data['message']['chat']['title'].'</strong>');
	}
    if ( $PRIVATE && !$ok_found ) {
         telegram_sendmessage( $USERID, telegram_option('emuser') );
    }

?>
