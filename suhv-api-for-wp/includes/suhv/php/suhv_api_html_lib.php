<?php
/***
 * Classes that return HTML Code from SUHV Classes like SuhvClub or SuhvTeam
 * 
 * @author Thomas Hardegger 
 * @version  10.01.2018
 * @todo Auf neue API umschreiben / die Funktionen bebehalten
 * STATUS: Reviewed
 */


class SwissUnihockey_Api_Public {

private static function cacheTime() {
        
  $cbase = 5*60; // 5 Min.
  $tag = date("w");
  //(Sonntag,Montag,Dienstag,Mittwoch,Donnerstag,Freitag,Samstag);
  $cacheValue = array(1*$cbase,6*$cbase,6*$cbase,2*$cbase,2*$cbase,2*$cbase,1*$cbase);
  return($cacheValue[$tag]);
}

private static function nearWeekend() {
               // So Mo Di (Mi=4) Do Fr Sa //
  $dayline = array(0,-1,-2,4,3,2,1);
  $tag = date("w");
  $today = strtotime("today");
  $daytoSunday = $dayline[$tag];
  $sunday = strtotime($daytoSunday." day",$today);
  $saturday = strtotime("-1 day",$sunday);
  $friday = strtotime("-2 day",$sunday);
  $weekendDays= array("Freitag"=>$friday,"Samstag"=>$saturday,"Sonntag"=>$sunday);

  return($weekendDays);
}

private static function suhvDown() {

  $options = get_option( 'SUHV_WP_plugin_options' );
  if (isset($options['SUHV_long_cache']) == 1) {

    $transient = "suhv-api-http-check";
    $allOK = get_transient( $transient );

    if ($allOK == FALSE) {

          $url = 'https://api-v2.swissunihockey.ch/api/games?mode=club&club_id=423403&season=2017';
          $ch = curl_init($url);
          curl_setopt($ch, CURLOPT_HEADER, true);    // we want headers
          curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
          curl_setopt($ch, CURLOPT_TIMEOUT,10);
          $response = curl_exec($ch);
          $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
          $stringinbodyOK = strpos($response, 'Chur');
          curl_close($ch);
          if ($stringinbodyOK and ($httpcode == 200)) {
            $allOK = TRUE; 
          }
          else {
            $homeurl = home_url();
            if (stripos($homeurl,"www.churunihockey.ch")>0) {
              $transientMail = "suhv-api-http-check-Mail";
              $mailOK = get_transient( $transientMail );
              if (!$mailOK) {
                $mailheaders = 'From: API-Check <'.'webmaster@churunihockey.ch'.'>' . "\r\n";
                $mailheaders .= "MIME-Version: 1.0\r\n";
                $mailheaders .= "Content-Type: text/html; charset=UTF-8\r\n";
                $message = "API Access via HTTP not OK <br />";
                $message .= $url."<br /><br />";
                $message .= "Check now: https://www.churunihockey.ch//wp-content/plugins/suhv-api-for-wp/includes/suhv/php/testAPI.php";
                $checkmail = wp_mail( "logs@teamchur.com", "HTTP Response Check: HTTPCODE ".$httpcode, $message, $mailheaders);
                set_transient( $transientMail, TRUE, 60*60); // nur alle 60 Min. ein Down Mail
              }
            }
            SwissUnihockey_Api_Public::log_me("HTTPCODE: ".$httpcode);
          }
          set_transient( $transient, $allOK, 15*60); // nur alle 15 Min. ein Down Check
    }
  }
  else $allOK = TRUE;
  return !$allOK;
}

/* ---------------------------------------------------------------------------------------------------- */
  public static function api_club_getGames($season, $club_ID, $club_shortname, $team_ID, $mode, $cache) {
    
    $team_ID = NULL;
    $trans_Factor = 1;
    $my_club_name = $club_shortname;
    //SwissUnihockey_Api_Public::log_me($my_club_name);
    $cup = FALSE;
    $transient = $club_ID.$team_ID."club_getGames".$season.$mode;
    $secure_trans = $transient."Secure";
    $semaphore = $club_ID.$team_ID."club_getGames-Flag";
    $value = get_transient( $transient );
    $flag = get_transient( $semaphore);
    $linkGame_ID = NULL;
    $likkGame_ID_before = NULL;

    if ($flag) $sema_value = "Sema: TRUE"; else  $sema_value = "Sema: FALSE";
    //SwissUnihockey_Api_Public::log_me($sema_value);
    
    if (!$cache) { $value = False; }

    if (SwissUnihockey_Api_Public::suhvDown()){ $value = TRUE;}

    if (($value == False) and ($flag == False)) {

      set_transient( $semaphore, TRUE, 5); // Keep out for 10 seconds - no Mail

      $go =  time();
      $api_calls = 0;
      $plugin_options = get_option( 'SUHV_WP_plugin_options' );
      $n_Games = $plugin_options['SUHV_club_games_limit'];
      $e_Mail_From = $plugin_options['SUHV_mail_send_from'];
      $e_Mail_Actual = $plugin_options['SUHV_mail_actual_result'];
      $e_Mail_Result = $plugin_options['SUHV_mail_final_result'];
      $tablepress ='';
      if ((isset( $plugin_options['SUHV_css_tablepress']) == 1)) $tablepress = " tablepress"; 


      // SwissUnihockey_Api_Public::log_me(array('function' => 'club_getGames', 'season' => $season, 'club_ID' =>  $club_ID, 'team_ID' =>   $team_ID, 'mode' => $mode));

      $mailheaders = 'From: Spielresultate <'.$e_Mail_From.'>' . "\r\n";
      $mailheaders .= "MIME-Version: 1.0\r\n";
      $mailheaders .= "Content-Type: text/html; charset=UTF-8\r\n";
      $skip = "<br />";

      $html = "";
      $html_res = "";
      $html_body = "";
      $mail_subjekt ="";

      $tage = array("Sonntag", "Montag", "Dienstag", "Mittwoch", "Donnerstag", "Freitag", "Samstag");
      $tag = date("w");
      $wochentag = $tage[$tag];

      $api = new SwissUnihockey_Public(); 
      $api_calls++;
      $details = $api->clubGames($season, $club_ID, $team_ID, $mode, array(
        
      )); 
      
// Eine Seite retour bei Page-Ende? 

      $data = $details->data; 
      $startpage = $data->context->page;
      // SwissUnihockey_Api_Public::log_me("club_getGames api-calls:". $api_calls." 1 page: ".$startpage);
//echo "Startpage: ".$startpage."<br>";
      if ($startpage!=1) { // eine Page retour wenn nicht erste
           $page = $startpage-1;
           $api = new SwissUnihockey_Public(); 
           $api_calls++;
           $details = $api->clubGames($season, $club_ID, $team_ID, $mode, array(
             'page' => $page
           )); 
          // SwissUnihockey_Api_Public::log_me("club_getGames api-calls:". $api_calls." 2 page: ".$page);
      }

      $data = $details->data; 
      $header_DateTime = $data->headers[0]->text;
      $header_Location = $data->headers[1]->text;
      $header_Leage = $data->headers[2]->text;
      $header_Home = $data->headers[3]->text;
      $header_Guest = $data->headers[4]->text;
      $header_Result = $data->headers[5]->text;
      $Cpos = strripos($my_club_name,'Chur');
      if (!is_bool($Cpos)) { $header_Result = "Res.";}
      $club_name = $data->title;
      $games = $data->regions[0]->rows;
      $attributes = $data->regions[0]->rows[0]->cells;
      
      $entries = count($games);
      
      $transient_games = $transient.$tag;
      $last_games = get_transient( $transient_games );
      if ($last_games == FALSE) {
        $last_games = $games;
        set_transient( $transient_games, $last_games, 2*55*60 );
        // echo "<br>Reset Games";60
      }
      $loop = FALSE;
      $tabs = $data->context->tabs;
      if ($tabs = "on") $loop = TRUE;
      $startpage = $data->context->page;
      $page = $startpage;

      $items = 0;
      $today = strtotime("now");
      $startdate = strtotime("-3 days",$today);
      $cTime = (SwissUnihockey_Api_Public::cacheTime() / 60)*$trans_Factor;

      if (!$cache) {
         $view_cache = "<br> cache = off / Display: ".$n_Games." Club: ".$my_club_name; 
        } else {$view_cache ="";
      }
      
      $html_head = "<table class=\"suhv-table suhv-planned-games-full".$tablepress."\">\n";
      $html_head .= "<caption>".$data->title."<br>".$wochentag.strftime(" - %H:%M")."  (".$cTime." min.)".$view_cache."</caption>";
      $html_head .= "<thead><tr><th class=\"suhv-date\">"."Datum,<br>Zeit".
      "</th><th class=\"suhv-place\">".$header_Location.
      "</th><th class=\"suhv-opponent\">".$header_Home.
      "</th><th class=\"suhv-opponent\">".$header_Guest."</th>";

      error_reporting(E_ALL & ~E_NOTICE);
      while ($loop) {
      $i = 0;
      do {
            $game_id = $games[$i]->link->ids[0];
            $game_detail_link = "https://www.swissunihockey.ch/de/game-detail?game_id=".$game_id;
            $game_date = $games[$i]->cells[0]->text[0];
            $game_time = $games[$i]->cells[0]->text[1];
            if ($game_time != "???") {
              $game_location_name = $games[$i]->cells[1]->text[0]; 
              $game_location = $games[$i]->cells[1]->text[1]; 
              $game_map_x = $games[$i]->cells[1]->link->x;
              $game_map_y = $games[$i]->cells[1]->link->y;
            }
            else {
              $game_location_name = "";
              $game_location = ""; 
              $game_map_x = "";
              $game_map_y = "";
            }
            $game_leage = $games[$i]->cells[2]->text[0]; 
            $game_homeclub = $games[$i]->cells[3]->text[0]; 
            $game_guestclub = $games[$i]->cells[4]->text[0]; 
            $game_result = $games[$i]->cells[5]->text[0];
            $linkGame_ID = $games[$i]->link->ids[0];
            $new_result = $game_result;
            $game_result_add = "";
            if (isset($games[$i]->cells[5]->text[1])) {$game_result_add = $games[$i]->cells[5]->text[1];}
            $game_home_result = substr($game_result,0,stripos($game_result,":"));
            $game_guest_result = substr($game_result,stripos($game_result,":")+1,strlen($game_result));
            $site_url = get_site_url();
            $site_display = substr($site_url,stripos($site_url,"://")+3);
          
            //Fehlerkorrektur für vom 7.1.2017
            if ($game_date=="today") $game_date="heute";
            if ($game_date=="yesterday") $game_date="gestern";

            if (($game_date=="heute") or ($game_date=="gestern"))  {
              if ($game_date=="heute")  { 
                $date_of_game = strtotime("today");
                $last_result = $last_games[$i]->cells[5]->text[0];
              }
              if ($game_date=="gestern") $date_of_game = strtotime("yesterday");
            }
            else{
             $date_parts = explode(".", $game_date); // dd.mm.yyyy in german
             $date_of_game = strtotime($date_parts[2]."-".$date_parts[1]."-".$date_parts[0]);
            }

            $game_maplink = "<a href=\"https://maps.google.ch/maps?q=".$game_map_y.",".$game_map_x."\""." target=\"_blank\" title= \"".$game_location_name."\">";
       
            $game_homeDisplay = $game_homeclub;
            $game_guestDisplay = $game_guestclub;

            /* If Cup?
            if (substr_count($game_leage,"Cup")>=1) { 
              $cup = TRUE;
            } */

            $special_league = "Junioren/-innen U14/U17 VM";
            $team_one = $my_club_name." I";
            $team_two = $my_club_name." II";
            $league_short = "U14/U17";
            
            $homeClass ="suhv-place";

            if ((substr_count($game_homeDisplay,$my_club_name)>=1) and (substr_count($game_guestDisplay,$my_club_name)>=1)) { 
              $resultClass = 'suhv-draw';
              $resultHomeClass = 'suhv-home';
              $resultGuestClass = 'suhv-home';
              if ($game_leage == $special_league){
                  $game_leage = str_replace ($special_league,$league_short,$game_leage); //new ab 2016
              }
              $game_homeDisplay = $game_leage." ".str_replace ($my_club_name,"",$game_homeDisplay);
              $game_guestDisplay = $game_leage." ".str_replace ($my_club_name,"",$game_guestDisplay);
            }
            else {
              if ($game_leage == $special_league){
                  $game_leage = str_replace ($special_league,$league_short,$game_leage); //new ab 2016
                  if ((substr_count($game_homeDisplay,$team_one )>=1) xor (substr_count($game_guestDisplay,$team_two)>=1) ){
                    if ((substr_count($game_homeDisplay,$team_two )>=1) or (substr_count($game_guestDisplay,$team_two)>=1) ){
                      $game_leage .=" II"; // Angzeige "U14/U17 II"
                     } 
                    else { 
                      if ((substr_count($game_homeDisplay,$team_one )>=1) or (substr_count($game_guestDisplay,$team_one)>=1) ){
                        $game_leage .=" I"; // Angzeige "U14/U17 I"
                      }
                   } 
                  }
                  else {
                    if ((substr_count($game_homeDisplay,$team_two )>=1) or (substr_count($game_guestDisplay,$team_two)>=1) ){
                      $game_leage .=" II"; // Angzeige "U14/U17 II"
                     } 
                    else { 
                      if ((substr_count($game_homeDisplay,$team_one )>=1) or (substr_count($game_guestDisplay,$team_one)>=1) ){
                        $game_leage .=" I"; // Angzeige "U14/U17 I"
                      }
                   } 
                  }
              }

              $game_leage = str_replace ("Junioren", "",$game_leage);
              $game_leage = str_replace ("Juniorinnen", "",$game_leage);
              $game_leage = str_replace ("/-innen ", "",$game_leage);
              $game_leage = str_replace ("Herren Aktive", "",$game_leage);
              $game_leage = str_replace ("Aktive", "",$game_leage);
              $game_leage = str_replace ("Schweizer", "",$game_leage);

              if ($game_home_result == $game_guest_result) { $resultClass = 'suhv-draw';} else {$resultClass = 'suhv-result';}

              if (substr_count($game_homeDisplay,$my_club_name)>=1) { 
                if ((substr_count($game_homeDisplay,$my_club_name)>=1) xor (substr_count($game_guestDisplay,$my_club_name)>=1))
                  $game_homeDisplay = $game_leage; 
                if ((substr_count($game_homeDisplay,$my_club_name)>=1) and (substr_count($game_guestDisplay,$my_club_name)>=1)) 
                  $game_homeDisplay = $league_short." ".str_replace ($my_club_name,"",$game_homeDisplay);
                $resultHomeClass = 'suhv-home';
                if ($game_home_result > $game_guest_result) { $resultClass = 'suhv-win';} else {$resultClass = 'suhv-lose';}
              }
              else $resultHomeClass = 'suhv-guest';
              if (substr_count($game_guestDisplay,$my_club_name)>=1) {
                if ((substr_count($game_homeDisplay,$my_club_name)>=1) xor (substr_count($game_guestDisplay,$my_club_name)>=1))
                  $game_guestDisplay = $game_leage;
                if ((substr_count($game_homeDisplay,$my_club_name)>=1) and (substr_count($game_guestDisplay,$my_club_name)>=1)) 
                  $game_guestDisplay = $league_short." ".str_replace ($my_club_name,"",$game_guestDisplay);
                $resultGuestClass = 'suhv-home';
                if ($game_guest_result > $game_home_result) { $resultClass = 'suhv-win';} else {$resultClass = 'suhv-lose';}
              }
              else $resultGuestClass = 'suhv-guest';
            }

            if ($game_result == "")  { 
              $resultClass = 'suhv-result';
            }
            if (($game_date=="heute") and ((substr_count($game_result,"*")!=0) or (substr_count($game_result,"-")!=0)))  {
              $resultClass = 'suhv-activ';
              if (substr_count($game_result,"-")!=0) {
                $game_result = "❓";
                $resultClass .= ' suhv-wait';
              }
            } 

            if ($game_date == "heute") {
              $game_summary = "-";
              $game_telegramm = "-";

              if ( ($new_result != $last_result) and (substr_count($new_result,"*")!=0) and ($new_result!="") and (substr_count($e_Mail_Actual,"@" )>=1)) {
               // echo "<br>new-Result ".$page.$i.": ".$new_result." - bisher: ".$last_result; 

               $last_games[$i] = $games[$i];
               $message = $game_location_name." (".$game_location."): <strong>".$new_result."</strong> im Spiel ".$game_homeDisplay." vs. ".$game_guestDisplay.$skip;
               $message .= "Spielbeginn: ".$game_time." aktuelle Zeit: ".strftime("%H:%M").$skip;
               $message .= "".$game_homeclub." vs. ". $game_guestclub."".$skip; 
               //$message .= $skip."Matchtelegramm:".$skip.$game_summary.$skip.$game_telegramm.$skip; 
               // $message .=  $skip."Info: Page: ".$page." Pos: ".$i." - bisher: ".$last_result.$skip; 
               $message .=  $skip."Diese Meldung wurde Dir durch <a href=\"".$site_url."\">".$site_display."</a> zugestellt.".$skip; 
               if ((substr_count($new_result,"0:0")!=0)) {
                 $checkmail = wp_mail( $e_Mail_Actual, "Spielstart: ".$game_leage." * ".$game_homeclub." vs. ". $game_guestclub.' '.$new_result, $message, $mailheaders);
               }
               else {
                 $checkmail = wp_mail( $e_Mail_Actual, "Zwischenresultat: ".$game_leage." * ".$game_homeclub." vs. ". $game_guestclub.' '.$new_result, $message, $mailheaders);
               }
               //echo "<br>mail ", $checkmail;
              }
              else {
               //echo "<br>old-Result ".$page.$i.": ".$new_result." - bisher: ".$last_result; 
              }
              if ( ($new_result != $last_result) and (substr_count($new_result,"*")==0) and ($new_result!="") and (substr_count($new_result,"-")==0) and (substr_count($e_Mail_Result,"@" )>=1) ){
               // echo "<br>NEUES-Resultat ".$page.$i.": ".$new_result." - bisher: ".$last_result; 
// ** NEU **//
                $api_game = new SwissUnihockey_Public(); 
                $details_game = $api_game->gameDetailsSummary($game_id, array()); 
                $response_type = $details_game->type;
                if ($response_type =="table") {
                  $game_summary = $details_game->data->regions[0]->rows[0]->cells[0]->text[0];
                  $game_sumdetail = $details_game->data->regions[0]->rows[0]->cells[1]->text[0];
                  $game_telegramm = $details_game->data->regions[0]->rows[0]->cells[2]->text[0].$skip.$details_game->data->regions[0]->rows[0]->cells[2]->text[1];

                  $last_games[$i] = $games[$i];
                  $message = $game_location_name." (".$game_location."): <strong>".$new_result."</strong> ".$game_result_add." im Spiel ".$game_homeclub." vs. ".$game_guestclub.$skip;
                  $message .= $skip."<a href=\"".$game_detail_link."\" title=\"Spieldetails\" >Matchtelegramm:</a>".$skip.$game_summary.$skip.$game_sumdetail.$skip.$game_telegramm.$skip; 
                  $message .=  $skip."Diese Meldung wurde Dir durch <a href=\"".$site_url."\">".$site_display."</a> zugestellt.".$skip; 
                  $checkmail = wp_mail( $e_Mail_Result, "Schluss-Resultat: ".$game_leage." - ".$game_homeclub." vs. ".$game_guestclub.' '.$new_result, $message, $mailheaders);
                }
              }
            }
            if (($items <= $n_Games)) {
              if (($date_of_game > $startdate) and ($linkGame_ID_before != $linkGame_ID)) {  //  and $cup
                $html_body .= "<tr". ($i % 2 == 1 ? ' class="alt"' : '') . "><td class=\"".$header_DateTime."\">".str_replace(".20",".",$game_date).", ".$game_time.
                "</td><td class=\"".$homeClass."\">".$game_maplink.$game_location."</a>".
                "</td><td class=\"".$resultHomeClass."\">".$game_homeDisplay.
                "</td><td class=\"".$resultGuestClass."\">".$game_guestDisplay;
                if (($game_result != "")) {
                  $html_res = "<th class=\"suhv-result\">".$header_Result."</th>"; 
                  $html_body .= "</td><td class=\"".$resultClass."\"><a href=\"".$game_detail_link."\" title=\"Spieldetails\" >".$game_result."<br>". $game_result_add."</a></td>";
                }
                else  $items++;
                $html_body .= "</tr>";
                // $cup = FALSE; // cup only
               }
            }
            else {
              $loop = FALSE;
            }
            $i++; 
            $linkGame_ID_before = $linkGame_ID;
         } while (($i < $entries) and ($loop));

         if ($data->slider->next == NULL) { $loop = FALSE; }// only this loop
         else {
           $page++; 
           if ($page >= ($startpage+10)) $loop = FALSE; // Don't Loop always. // max 10 Pages
           $api = new SwissUnihockey_Public(); 
           $api_calls++;
           $details = $api->clubGames($season, $club_ID, $team_ID, $mode, array(
              'page' => $page
           )); 
           $data = $details->data; 
           $games = $data->regions[0]->rows;
           $entries = count($games);
           // SwissUnihockey_Api_Public::log_me("club_getGames api-calls:". $api_calls." entries: ".$entries." items: ".$items." page: ".$page." n: ".$n_Games);
        } // end else
      } // end While
        // Report all errors
      error_reporting(E_ALL);
      $html_head .= $html_res;
      $html_head .= "</tr></thead><tbody>";
      $html .= $html_head;
      $html .= $html_body;
      $html .= "</tbody>";
      $html .= "</table>";
      $stop =  time();
      $secs = ($stop- $go);
      //SwissUnihockey_Api_Public::log_me("club_getGames eval-time: ".$secs." secs  api-calls: ". $api_calls);
      //$html2 = str_replace ("</table>","defekt",$html);// for test
      set_transient( $transient, $html, SwissUnihockey_Api_Public::cacheTime()*$trans_Factor );
      set_transient( $transient_games, $last_games, 2*60*60 );
      if (($secs<=10) and isset($data))  {
       $safe_html = str_replace (" min.)"," min. cache value)",$html);
       set_transient( $secure_trans, $safe_html, 12*3600); 
      }
    } //end If
    else { 
        $htmlpos = strpos($value,"</table>");
        $len = strlen($value);
        if (($htmlpos) and ($len > 300)) { 
          $html = $value; // Abfrage war OK
          //SwissUnihockey_Api_Public::log_me("API Cache OK pos: ".$htmlpos." len: ".$len);
        }
        else {
          $value = get_transient( $secure_trans ); // letzte gute Version holen bei Time-Out der API 2.0 von Swissunihockey
          //SwissUnihockey_Api_Public::log_me("API Cache Restore!");
          $html = $value; 
        }
    }
    return $html;
	}

/* ---------------------------------------------------------------------------------------------------- */
  public static function api_club_getCupGames($season, $club_ID, $club_shortname, $team_ID, $mode, $cache) {
    
    $team_ID = NULL;
    $trans_Factor = 10;
    $my_club_name = $club_shortname;
    //SwissUnihockey_Api_Public::log_me($my_club_name);
    $cup = FALSE;
    $transient = $club_ID.$team_ID."club_getCupGames".$season.$mode;
    $secure_trans = $transient."Secure";
    $semaphore = $club_ID.$team_ID."club_getGames-Flag";
    $value = get_transient( $transient );
    $flag = get_transient( $semaphore);
    $linkGame_ID = NULL;
    $likkGame_ID_before = NULL;

    if ($flag) $sema_value = "Sema: TRUE"; else  $sema_value = "Sema: FALSE";
    //SwissUnihockey_Api_Public::log_me($sema_value);
    
    if (!$cache) { $value = False; }
    if (SwissUnihockey_Api_Public::suhvDown()){ $value = TRUE; }

    if (($value == False) and ($flag == False)) {

      set_transient( $semaphore, TRUE, 5); // Keep out for 10 seconds - no Mail

      $go =  time();
      $api_calls = 0;
      $plugin_options = get_option( 'SUHV_WP_plugin_options' );
      $n_Games = $plugin_options['SUHV_club_games_limit'];
      $e_Mail_From = $plugin_options['SUHV_mail_send_from'];
      $e_Mail_Actual = $plugin_options['SUHV_mail_actual_result'];
      $e_Mail_Result = $plugin_options['SUHV_mail_final_result'];
      $tablepress ='';
      if ((isset( $plugin_options['SUHV_css_tablepress']) == 1)) $tablepress = " tablepress";

      // SwissUnihockey_Api_Public::log_me(array('function' => 'club_getGames', 'season' => $season, 'club_ID' =>  $club_ID, 'team_ID' =>   $team_ID, 'mode' => $mode));

      $mailheaders = 'From: Spielresultate <'.$e_Mail_From.'>' . "\r\n";
      $mailheaders .= "MIME-Version: 1.0\r\n";
      $mailheaders .= "Content-Type: text/html; charset=UTF-8\r\n";
      $skip = "<br />";

      $html = "";
      $html_res = "";
      $html_body = "";
      $mail_subjekt ="";

      $tage = array("Sonntag", "Montag", "Dienstag", "Mittwoch", "Donnerstag", "Freitag", "Samstag");
      $tag = date("w");
      $wochentag = $tage[$tag];

      $api = new SwissUnihockey_Public(); 
      $api_calls++;
      $details = $api->clubGames($season, $club_ID, $team_ID, $mode, array(
       
      )); 
      
// Eine Seite retour bei Page-Ende? 

      $data = $details->data; 
      $startpage = $data->context->page;
      // SwissUnihockey_Api_Public::log_me("club_getGames api-calls:". $api_calls." 1 page: ".$startpage);

      if ($startpage!=1) { // eine Page retour wenn nicht erste
           $page = $startpage-1;
           $api = new SwissUnihockey_Public(); 
           $api_calls++;
           $details = $api->clubGames($season, $club_ID, $team_ID, $mode, array(
              'page' => $page
           )); 
          // SwissUnihockey_Api_Public::log_me("club_getGames api-calls:". $api_calls." 2 page: ".$page);
      }

      $data = $details->data; 
      $header_DateTime = $data->headers[0]->text;
      $header_Location = $data->headers[1]->text;
      $header_Leage = $data->headers[2]->text;
      $header_Home = $data->headers[3]->text;
      $header_Guest = $data->headers[4]->text;
      $header_Result = $data->headers[5]->text;
      $header_Result = "Res.";
      $club_name = $data->title;
      $games = $data->regions[0]->rows;
      $attributes = $data->regions[0]->rows[0]->cells;
      
      $entries = count($games);
      
      $transient_games = $transient.$tag;
      $last_games = get_transient( $transient_games );
      if ($last_games == FALSE) {
        $last_games = $games;
        set_transient( $transient_games, $last_games, 2*60*60 );
        // echo "<br>Reset Games";
      }
      $loop = FALSE;
      $tabs = $data->context->tabs;
      if ($tabs = "on") $loop = TRUE;
      $startpage = $data->context->page;
      $page = $startpage;

      $items = 0;
      $today = strtotime("now");
      $startdate = strtotime("-3 days",$today);
      $cTime = (SwissUnihockey_Api_Public::cacheTime() / 60)*$trans_Factor;

      if (!$cache) {
         $view_cache = "<br> cache = off / Display: ".$n_Games; 
         // $view_cache =."<br>emails:<br> ".$e_Mail_Actual."<br>".$e_Mail_Result."<br>".$e_Mail_From;
        } else {$view_cache ="";
      }
      
      $html_head = "<table class=\"suhv-table suhv-planned-games-full".$tablepress."\">\n";
      //$html_head .= "<caption>".$data->title."<br>".$wochentag.strftime(" - %H:%M")."  (".$cTime." min.)".$view_cache."</caption>";
      $html_head .= "<thead><tr><th class=\"suhv-date\">"."Datum,<br>Zeit".
      "</th><th class=\"suhv-place\">".$header_Location.
      "</th><th class=\"suhv-opponent\">".$header_Home.
      "</th><th class=\"suhv-opponent\">".$header_Guest."</th>";

      error_reporting(E_ALL & ~E_NOTICE);
      while ($loop) {
      $i = 0;
      do {
            $game_id = $games[$i]->link->ids[0];
            $game_detail_link = "https://www.swissunihockey.ch/de/game-detail?game_id=".$game_id;
            $game_date = $games[$i]->cells[0]->text[0];
            $game_time = $games[$i]->cells[0]->text[1];
            if ($game_time != "???") {
              $game_location_name = $games[$i]->cells[1]->text[0]; 
              $game_location = $games[$i]->cells[1]->text[1]; 
              $game_map_x = $games[$i]->cells[1]->link->x;
              $game_map_y = $games[$i]->cells[1]->link->y;
            }
            else {
              $game_location_name = "";
              $game_location = ""; 
              $game_map_x = "";
              $game_map_y = "";
            }
            $game_leage = $games[$i]->cells[2]->text[0]; 
            $game_homeclub = $games[$i]->cells[3]->text[0]; 
            $game_guestclub = $games[$i]->cells[4]->text[0]; 
            $game_result = $games[$i]->cells[5]->text[0];
            $linkGame_ID = $games[$i]->link->ids[0];
            $new_result = $game_result;
            $game_result_add = "";
            if (isset($games[$i]->cells[5]->text[1])) {$game_result_add = $games[$i]->cells[5]->text[1];}
            $game_home_result = substr($game_result,0,stripos($game_result,":"));
            $game_guest_result = substr($game_result,stripos($game_result,":")+1,strlen($game_result));
            $site_url = get_site_url();
            $site_display = substr($site_url,stripos($site_url,"://")+3);
          
            if ($game_date=="today") $game_date="heute";
            if ($game_date=="yesterday") $game_date="gestern";

            if (($game_date=="heute") or ($game_date=="gestern"))  {
              if ($game_date=="heute")  { 
                $date_of_game = strtotime("today");
                $last_result = $last_games[$i]->cells[5]->text[0];
              }
              if ($game_date=="gestern") $date_of_game = strtotime("yesterday");
            }
            else{
             $date_parts = explode(".", $game_date); // dd.mm.yyyy in german
             $date_of_game = strtotime($date_parts[2]."-".$date_parts[1]."-".$date_parts[0]);
            }

            $game_maplink = "<a href=\"https://maps.google.ch/maps?q=".$game_map_y.",".$game_map_x."\""." target=\"_blank\" title= \"".$game_location_name."\">";
       
            $game_homeDisplay = $game_homeclub;
            $game_guestDisplay = $game_guestclub;

            // If Cup?
            if (substr_count($game_leage,"Cup")>=1) { 
              $cup = TRUE;
            }

            $special_league = "Junioren/-innen U14/U17 VM";
            $team_one = $my_club_name." I";
            $team_two = $my_club_name." II";
            $league_short = "U14/U17";
            if ($game_leage == $special_league){
                $game_leage = str_replace ($special_league,$league_short,$game_leage); //new ab 2016
                if ((substr_count($game_homeDisplay,$team_one )>=1) xor (substr_count($game_guestDisplay,$team_two)>=1) ){
                  if ((substr_count($game_homeDisplay,$team_two )>=1) or (substr_count($game_guestDisplay,$team_two)>=1) ){
                    $game_leage .=" II"; // Angzeige "U14/U17 II"
                   } 
                  else { 
                    if ((substr_count($game_homeDisplay,$team_one )>=1) or (substr_count($game_guestDisplay,$team_one)>=1) ){
                      $game_leage .=" I"; // Angzeige "U14/U17 I"
                    }
                 } 
                }
                else {
                  if ((substr_count($game_homeDisplay,$team_two )>=1) or (substr_count($game_guestDisplay,$team_two)>=1) ){
                    $game_leage .=" II"; // Angzeige "U14/U17 II"
                   } 
                  else { 
                    if ((substr_count($game_homeDisplay,$team_one )>=1) or (substr_count($game_guestDisplay,$team_one)>=1) ){
                      $game_leage .=" I"; // Angzeige "U14/U17 I"
                    }
                 } 
                }
            }

            $game_leage = str_replace ("Junioren", "",$game_leage);
            $game_leage = str_replace ("Juniorinnen", "",$game_leage);
            $game_leage = str_replace ("/-innen ", "",$game_leage);
            $game_leage = str_replace ("Herren Aktive", "",$game_leage);
            $game_leage = str_replace ("Aktive", "",$game_leage);
            $game_leage = str_replace ("Schweizer", "",$game_leage);
            //$game_leage = str_replace ("Damen", "",$game_leage);
            //$game_leage = str_replace ("Herren", "",$game_leage);
            $homeClass ="suhv-place";
            if ($game_home_result == $game_guest_result) { $resultClass = 'suhv-draw';} else {$resultClass = 'suhv-result';}

            if (substr_count($game_homeDisplay,$my_club_name)>=1) { 
              if ((substr_count($game_homeDisplay,$my_club_name)>=1) xor (substr_count($game_guestDisplay,$my_club_name)>=1))
                $game_homeDisplay = $game_leage; 
              if ((substr_count($game_homeDisplay,$my_club_name)>=1) and (substr_count($game_guestDisplay,$my_club_name)>=1)) 
                $game_homeDisplay = $league_short." ".str_replace ($my_club_name,"",$game_homeDisplay);
              $resultHomeClass = 'suhv-home';
              if ($game_home_result > $game_guest_result) { $resultClass = 'suhv-win';} else {$resultClass = 'suhv-lose';}
            }
            else $resultHomeClass = 'suhv-guest';
            if (substr_count($game_guestDisplay,$my_club_name)>=1) {
              if ((substr_count($game_homeDisplay,$my_club_name)>=1) xor (substr_count($game_guestDisplay,$my_club_name)>=1))
                $game_guestDisplay = $game_leage;
              if ((substr_count($game_homeDisplay,$my_club_name)>=1) and (substr_count($game_guestDisplay,$my_club_name)>=1)) 
                $game_guestDisplay = $league_short." ".str_replace ($my_club_name,"",$game_guestDisplay);
              $resultGuestClass = 'suhv-home';
              if ($game_guest_result > $game_home_result) { $resultClass = 'suhv-win';} else {$resultClass = 'suhv-lose';}
            }
            else $resultGuestClass = 'suhv-guest';

            if ($game_result == "")  { 
              $resultClass = 'suhv-result';
            }
            if (($game_date=="heute") and ((substr_count($game_result,"*")!=0) or (substr_count($game_result,"-")!=0)))  {
              $resultClass = 'suhv-activ';
              if (substr_count($game_result,"-")!=0) {
                $game_result = "❓";
                $resultClass .= ' suhv-wait';
              }
            } 
            /* no email */
            if (($items <= $n_Games)) {
               if (($date_of_game > $startdate) and ($linkGame_ID_before != $linkGame_ID) and $cup) {
                $html_body .= "<tr". ($i % 2 == 1 ? ' class="alt"' : '') . "><td class=\"".$header_DateTime."\">".str_replace(".20",".",$game_date).", ".$game_time.
                "</td><td class=\"".$homeClass."\">".$game_maplink.$game_location."</a>".
                "</td><td class=\"".$resultHomeClass."\">".$game_homeDisplay.
                "</td><td class=\"".$resultGuestClass."\">".$game_guestDisplay;
                if (($game_result != "")) {
                  $html_res = "<th class=\"suhv-result\">".$header_Result."</th>"; 
                  $html_body .= "</td><td class=\"".$resultClass."\"><a href=\"".$game_detail_link."\" title=\"Spieldetails\" >"."<strong>".$game_result."</strong><br>". $game_result_add."</a></td>";
                }
                else  $items++;
                $html_body .= "</tr>";
               }
            }
            else {
              $loop = FALSE;
            }
            $i++; 
            $cup = FALSE;
            $linkGame_ID_before = $linkGame_ID;
         } while (($i < $entries) and ($loop));

         if ($data->slider->next == NULL) { $loop = FALSE; }// only this loop
         else {
           $page++; 
           if ($page >= ($startpage+10)) $loop = FALSE; // Don't Loop always. // max 10 Pages
           $api = new SwissUnihockey_Public(); 
           $api_calls++;
           $details = $api->clubGames($season, $club_ID, $team_ID, $mode, array(
             'page' => $page
           )); 
           $data = $details->data; 
           $games = $data->regions[0]->rows;
           $entries = count($games);
           // SwissUnihockey_Api_Public::log_me("club_getGames api-calls:". $api_calls." entries: ".$entries." items: ".$items." page: ".$page." n: ".$n_Games);
        } // end else
      } // end While
        // Report all errors
      error_reporting(E_ALL);
      $html_head .= $html_res;
      $html_head .= "</tr></thead><tbody>";
      $html .= $html_head;
      $html .= $html_body;
      $html .= "</tbody>";
      $html .= "</table>";
      $stop =  time();
      $secs = ($stop- $go);
      //SwissUnihockey_Api_Public::log_me("club_getGames eval-time: ".$secs." secs  api-calls: ". $api_calls);
      //$html2 = str_replace ("</table>","defekt",$html);// for test
      set_transient( $transient, $html, SwissUnihockey_Api_Public::cacheTime()*$trans_Factor );
      set_transient( $transient_games, $last_games, 2*60*60 );
      if (($secs<=10) and isset($data))  {
       $safe_html = str_replace (" min.)"," min. cache value)",$html);
       set_transient( $secure_trans, $safe_html, 12*3600); 
      }
    } //end If
    else { 
        $htmlpos = strpos($value,"</table>");
        $len = strlen($value);
        if (($htmlpos) and ($len > 300)) { 
          $html = $value; // Abfrage war OK
          //SwissUnihockey_Api_Public::log_me("API Cache OK pos: ".$htmlpos." len: ".$len);
        }
        else {
          $value = get_transient( $secure_trans ); // letzte gute Version holen bei Time-Out der API 2.0 von Swissunihockey
          //SwissUnihockey_Api_Public::log_me("API Cache Restore!");
          $html = $value; 
        }
    }
    return $html;
  }

/* ---------------------------------------------------------------------------------------------------- */
  public static function api_club_getWeekendGames($season, $club_ID, $club_shortname, $team_ID, $mode, $start_date, $end_date, $cache) {
    
    date_default_timezone_set("Europe/Paris");
    $my_club_name = $club_shortname;
    $linkGame_ID = NULL;
    $linkGame_ID_before = NULL;

  
    $date_parts = explode(".", $start_date); // dd.mm.yyyy in german
    $start_date_us = strtotime($date_parts[2]."-".$date_parts[1]."-".$date_parts[0]);
    //echo "<br>start:", date("d.m.Y", $start_date_us) . "<br>";
    $date_parts = explode(".", $end_date); // dd.mm.yyyy in german
    $end_date_us = strtotime($date_parts[2]."-".$date_parts[1]."-".$date_parts[0]);
    //echo "<br>end:", date("d.m.Y", $end_date_us) . "<br>";
    $weekend = FALSE;
    $date_description = "Spiele vom ".$start_date;
    if (strpos($start_date,"2015")>0) {
      $weekend = TRUE;
      $weekendDays = SwissUnihockey_Api_Public::nearWeekend();
      $start_date_us = $weekendDays["Freitag"];
      $end_date_us = $weekendDays["Sonntag"];
      $date_description = "Spiele vom Wochenende";
      $start_date = date("d.m.Y",$start_date_us);
      $end_date = date("d.m.Y",$end_date_us);
    }

    $team_ID = NULL;
    $trans_Factor = 2;
    $transient = $club_ID.$team_ID."club_getWeekendGames".$season.$mode.$start_date.$end_date."test";
    $value = get_transient( $transient );

    if (!$cache) $value = False;
    if (SwissUnihockey_Api_Public::suhvDown()){ $value = TRUE; }
    
    if ($value == False) {

      //SwissUnihockey_Api_Public::log_me(array('function' => 'club_getWeekendGames', 'season' => $season, 'club_ID' =>  $club_ID, 'team_ID' =>   $team_ID, 'mode' => $mode, 'startdate' => $start_date, 'enddate' => $end_date));

      $html = "";
      $html_res = "";
      $html_body = "";

      $plugin_options = get_option( 'SUHV_WP_plugin_options' );
      $tablepress ='';
      if ((isset( $plugin_options['SUHV_css_tablepress']) == 1)) $tablepress = " tablepress";
      $n_Games = 40;

      $tage = array("Sonntag", "Montag", "Dienstag", "Mittwoch", "Donnerstag", "Freitag", "Samstag");
      $tag = date("w");
      $wochentag = $tage[$tag];
      $tag = date("w",$start_date_us);
      $start_tag = $tage[$tag];
      $tag = date("w",$end_date_us);
      $end_tag = $tage[$tag];

      $api = new SwissUnihockey_Public(); 
      $details = $api->clubGames($season, $club_ID, $team_ID, $mode, array(
       
      )); 
      
// Eine Seite retour bei Page-Ende? 

      $data = $details->data; 
      $startpage = $data->context->page;

      if ($startpage!=1) { // eine Page retour wenn nicht erste
           $page = $startpage-1;
           $api = new SwissUnihockey_Public(); 
           $details = $api->clubGames($season, $club_ID, $team_ID, $mode, array(
             'page' => $page
       )); 
      }

      $data = $details->data; 
      $header_DateTime = $data->headers[0]->text;
      $header_Location = $data->headers[1]->text;
      $header_Leage = $data->headers[2]->text;
      $header_Home = $data->headers[3]->text;
      $header_Guest = $data->headers[4]->text;
      $header_Result = $data->headers[5]->text;
      //$header_Result = "Res.";
      $club_name = $data->title;
      $games = $data->regions[0]->rows;
      $attributes = $data->regions[0]->rows[0]->cells;
      
      $entries = count($games);

      $loop = FALSE;
      $tabs = $data->context->tabs;
      if ($tabs = "on") $loop = TRUE;
      $startpage = $data->context->page;
      $page = $startpage;

      $items = 0;
      $today = strtotime("now");
      $startdate = strtotime("-1 days",$start_date_us);
      $cTime = (SwissUnihockey_Api_Public::cacheTime() / 60)*$trans_Factor;
      $homeClass ="suhv-place";
      $html_head = "<table class=\"suhv-table suhv-planned-games-full".$tablepress."\">\n";
      if ($weekend) 
        $html_head .= "<caption>".$date_description." ".$start_tag." ".$start_date." bis ".$end_tag." ".$end_date."</caption>";
      else
        $html_head .= "<caption>"."Spiele vom ".$start_tag." ".$start_date." bis ".$end_tag." ".$end_date."</caption>";
      $html_head .= "<thead><tr><th class=\"suhv-date\">"."Datum,<br>Zeit".
      "</th><th class=\"suhv-place\">".$header_Location.
      "</th><th class=\"suhv-opponent\">".$header_Home.
      "</th><th class=\"suhv-opponent\">".$header_Guest."</th>";

      error_reporting(E_ALL & ~E_NOTICE);
      while ($loop) {
      $i = 0;
      do {
            $game_id = $games[$i]->link->ids[0];
            $game_detail_link = "https://www.swissunihockey.ch/de/game-detail?game_id=".$game_id;
            $game_date = $games[$i]->cells[0]->text[0];
            $game_time = $games[$i]->cells[0]->text[1];
            if ($game_time != "???") {
              $game_location_name = $games[$i]->cells[1]->text[0]; 
              $game_location = $games[$i]->cells[1]->text[1]; 
              $game_map_x = $games[$i]->cells[1]->link->x;
              $game_map_y = $games[$i]->cells[1]->link->y;
            }
            else {
              $game_location_name = "";
              $game_location = ""; 
              $game_map_x = "";
              $game_map_y = "";
            }
            $game_leage = $games[$i]->cells[2]->text[0]; 
            $game_homeclub = $games[$i]->cells[3]->text[0]; 
            $game_guestclub = $games[$i]->cells[4]->text[0]; 
            $game_result = $games[$i]->cells[5]->text[0];
            $linkGame_ID = $games[$i]->link->ids[0];
            $new_result = $game_result;
            $game_result_add = "";
            if (isset($games[$i]->cells[5]->text[1])) {$game_result_add = $games[$i]->cells[5]->text[1];}
            $game_home_result = substr($game_result,0,stripos($game_result,":"));
            $game_guest_result = substr($game_result,stripos($game_result,":")+1,strlen($game_result));
            $site_url = get_site_url();
            $site_display = substr($site_url,stripos($site_url,"://")+3);
          
            //Fehlerkorrektur für vom 7.1.2017
            if ($game_date=="today") $game_date="heute";
            if ($game_date=="yesterday") $game_date="gestern";

            if (($game_date=="heute") or ($game_date=="gestern"))  {
              if ($game_date=="heute")  { 
                $date_of_game = strtotime("today");
                $last_result = $last_games[$i]->cells[5]->text[0];
              }
              if ($game_date=="gestern") $date_of_game = strtotime("yesterday");
            }
            else{
             $date_parts = explode(".", $game_date); // dd.mm.yyyy in german
             $date_of_game = strtotime($date_parts[2]."-".$date_parts[1]."-".$date_parts[0]);
            }

            $game_maplink = "<a href=\"https://maps.google.ch/maps?q=".$game_map_y.",".$game_map_x."\""." target=\"_blank\" title= \"".$game_location_name."\">";
       
            $game_homeDisplay = $game_homeclub;
            $game_guestDisplay = $game_guestclub;

            /* If Cup?
            if (substr_count($game_leage,"Cup")>=1) { 
              $cup = TRUE;
            } */

            $special_league = "Junioren/-innen U14/U17 VM";
            $team_one = $my_club_name." I";
            $team_two = $my_club_name." II";
            $league_short = "U14/U17";
              if ((substr_count($game_homeDisplay,$my_club_name)>=1) and (substr_count($game_guestDisplay,$my_club_name)>=1)) { 
              $resultClass = 'suhv-draw';
              $resultHomeClass = 'suhv-home';
              $resultGuestClass = 'suhv-home';
              if ($game_leage == $special_league){
                  $game_leage = str_replace ($special_league,$league_short,$game_leage); //new ab 2016
              }
              $game_homeDisplay = $game_leage." ".str_replace ($my_club_name,"",$game_homeDisplay);
              $game_guestDisplay = $game_leage." ".str_replace ($my_club_name,"",$game_guestDisplay);
            }
            else {
              if ($game_leage == $special_league){
                  $game_leage = str_replace ($special_league,$league_short,$game_leage); //new ab 2016
                  if ((substr_count($game_homeDisplay,$team_one )>=1) xor (substr_count($game_guestDisplay,$team_two)>=1) ){
                    if ((substr_count($game_homeDisplay,$team_two )>=1) or (substr_count($game_guestDisplay,$team_two)>=1) ){
                      $game_leage .=" II"; // Angzeige "U14/U17 II"
                     } 
                    else { 
                      if ((substr_count($game_homeDisplay,$team_one )>=1) or (substr_count($game_guestDisplay,$team_one)>=1) ){
                        $game_leage .=" I"; // Angzeige "U14/U17 I"
                      }
                   } 
                  }
                  else {
                    if ((substr_count($game_homeDisplay,$team_two )>=1) or (substr_count($game_guestDisplay,$team_two)>=1) ){
                      $game_leage .=" II"; // Angzeige "U14/U17 II"
                     } 
                    else { 
                      if ((substr_count($game_homeDisplay,$team_one )>=1) or (substr_count($game_guestDisplay,$team_one)>=1) ){
                        $game_leage .=" I"; // Angzeige "U14/U17 I"
                      }
                   } 
                  }
              }

              $game_leage = str_replace ("Junioren", "",$game_leage);
              $game_leage = str_replace ("Juniorinnen", "",$game_leage);
              $game_leage = str_replace ("Herren Aktive", "",$game_leage);
              $game_leage = str_replace ("Aktive", "",$game_leage);
              $game_leage = str_replace ("Schweizer", "",$game_leage);

              if ($game_home_result == $game_guest_result) { $resultClass = 'suhv-draw';} else {$resultClass = 'suhv-result';}

              if (substr_count($game_homeDisplay,$my_club_name)>=1) { 
                if ((substr_count($game_homeDisplay,$my_club_name)>=1) xor (substr_count($game_guestDisplay,$my_club_name)>=1))
                  $game_homeDisplay = $game_leage; 
                if ((substr_count($game_homeDisplay,$my_club_name)>=1) and (substr_count($game_guestDisplay,$my_club_name)>=1)) 
                  $game_homeDisplay = $league_short." ".str_replace ($my_club_name,"",$game_homeDisplay);
                $resultHomeClass = 'suhv-home';
                if ($game_home_result > $game_guest_result) { $resultClass = 'suhv-win';} else {$resultClass = 'suhv-lose';}
              }
              else $resultHomeClass = 'suhv-guest';
              if (substr_count($game_guestDisplay,$my_club_name)>=1) {
                if ((substr_count($game_homeDisplay,$my_club_name)>=1) xor (substr_count($game_guestDisplay,$my_club_name)>=1))
                  $game_guestDisplay = $game_leage;
                if ((substr_count($game_homeDisplay,$my_club_name)>=1) and (substr_count($game_guestDisplay,$my_club_name)>=1)) 
                  $game_guestDisplay = $league_short." ".str_replace ($my_club_name,"",$game_guestDisplay);
                $resultGuestClass = 'suhv-home';
                if ($game_guest_result > $game_home_result) { $resultClass = 'suhv-win';} else {$resultClass = 'suhv-lose';}
              }
              else $resultGuestClass = 'suhv-guest';
            }

            if ($game_result == "")  { 
              $resultClass = 'suhv-result';
            }
            if (($game_date=="heute") and ((substr_count($game_result,"*")!=0) or (substr_count($game_result,"-")!=0)))  {
              $resultClass = 'suhv-activ';
              if (substr_count($game_result,"-")!=0) {
                $game_result = "❓";
                $resultClass .= ' suhv-wait';
              }
            } 
            /* no email*/
          
            if (($items <= $n_Games) and ($date_of_game <= $end_date_us)) {
              if (($date_of_game > $startdate) and ($date_of_game <= $end_date_us) and ($linkGame_ID_before != $linkGame_ID) ) {  //   and $cup
                $html_body .= "<tr". ($i % 2 == 1 ? ' class="alt"' : '') . "><td class=\"".$header_DateTime."\">".str_replace(".20",".",$game_date).", ".$game_time.
                "</td><td class=\"".$homeClass."\">".$game_maplink.$game_location."</a>".
                "</td><td class=\"".$resultHomeClass."\">".$game_homeDisplay.
                "</td><td class=\"".$resultGuestClass."\">".$game_guestDisplay;
                if (($game_result != "")) {
                  $html_res = "<th class=\"suhv-result\">".$header_Result."</th>"; 
                  $html_body .= "</td><td class=\"".$resultClass."\"><a href=\"".$game_detail_link."\" title=\"Spieldetails\" >".$game_result."<br>". $game_result_add."</a></td>";
                }
                else  $items++;
                $html_body .= "</tr>";
                // $cup = FALSE; // cup only
               }
            }
            else {
              $loop = FALSE;
            }
            $i++; 
            $linkGame_ID_before = $linkGame_ID;
         } while (($i < $entries) and ($loop));

         if ($data->slider->next == NULL) { $loop = FALSE; }// only this loop
         else {
           $page++; 
           if ($page >= ($startpage+10)) $loop = FALSE; // Don't Loop always. // max 10 Pages
           $api = new SwissUnihockey_Public(); 
           $api_calls++;
           $details = $api->clubGames($season, $club_ID, $team_ID, $mode, array(
              'page' => $page
           )); 
           $data = $details->data; 
           $games = $data->regions[0]->rows;
           $entries = count($games);
           // SwissUnihockey_Api_Public::log_me("club_getGames api-calls:". $api_calls." entries: ".$entries." items: ".$items." page: ".$page." n: ".$n_Games);
        } // end else
      } // end While
        // Report all errors
      error_reporting(E_ALL);

      $html_head .= $html_res;
      $html_head .= "</tr></thead><tbody>";
      $html .= $html_head;
      $html .= $html_body;
      $html .= "</tbody>";
      $html .= "</table>";
     
      set_transient( $transient, $html, SwissUnihockey_Api_Public::cacheTime()*$trans_Factor );
      //output some debug string
      // error_log( 'api_club_getGames:');
      //error_log( print_r(strftime("%A - %H:%M")) );
    } //end If
    else { $html = $value; }
    return $html;
  }
/* ---------------------------------------------------------------------------------------------------- */
  public static function api_team_getGameDetails($season, $club_ID, $club_shortname, $team_ID, $mode, $start_date, $end_date, $cache) {
    
    date_default_timezone_set("Europe/Paris");
    $my_club_name = $club_shortname;
    $linkGame_ID = NULL;
    $linkGame_ID_before = NULL;
  
    $date_parts = explode(".", $start_date); // dd.mm.yyyy in german
    $start_date_us = strtotime($date_parts[2]."-".$date_parts[1]."-".$date_parts[0]);
    //echo "<br>start:", date("d.m.Y", $start_date_us) . "<br>";
    $date_parts = explode(".", $end_date); // dd.mm.yyyy in german
    $end_date_us = strtotime($date_parts[2]."-".$date_parts[1]."-".$date_parts[0]);
    //echo "<br>end:", date("d.m.Y", $end_date_us) . "<br>";
    $weekend = FALSE;
    $date_description = "Spiele-Details vom ".$start_date;
    if (strpos($start_date,"2015")>0) {
      $weekend = TRUE;
      $weekendDays = SwissUnihockey_Api_Public::nearWeekend();
      $start_date_us = $weekendDays["Freitag"];
      $end_date_us = $weekendDays["Sonntag"];
      $date_description = "Spiele-Details vom Wochenende";
      $start_date = date("d.m.Y",$start_date_us);
      $end_date = date("d.m.Y",$end_date_us);
    }

    //$team_ID = NULL;
    $trans_Factor = 1;
    $transient = $club_ID.$team_ID."club_getGameDetails".$season.$mode.$start_date.$end_date."GameDetails";
    $value = get_transient( $transient );

    if (!$cache) $value = False;
    if (SwissUnihockey_Api_Public::suhvDown()){ $value = TRUE; }
    
    if ($value == False) {

      // SwissUnihockey_Api_Public::log_me(array('function' => 'club_getGameDetails 2', 'season' => $season, 'club_ID' =>  $club_ID, 'team_ID' =>   $team_ID, 'mode' => $mode, 'startdate' => $start_date, 'enddate' => $end_date));

      $html = "";
      $html_res = "";
      $html_body = "";
  

      $plugin_options = get_option( 'SUHV_WP_plugin_options' );
      $tablepress ='';
      if ((isset( $plugin_options['SUHV_css_tablepress']) == 1)) $tablepress = " tablepress";
      $n_Games = 40;

      $tage = array("Sonntag", "Montag", "Dienstag", "Mittwoch", "Donnerstag", "Freitag", "Samstag");
      $tag = date("w");
      $wochentag = $tage[$tag];
      $tag = date("w",$start_date_us);
      $start_tag = $tage[$tag];
      $tag = date("w",$end_date_us);
      $end_tag = $tage[$tag];

      $api = new SwissUnihockey_Public(); 
      $details = $api->clubGames($season, $club_ID, $team_ID, $mode, array(

      )); 

      // Eine Seite retour bei Page-Ende? 

      $data = $details->data; 
      $startpage = $data->context->page;

      if ($startpage!=1) { // eine Page retour wenn nicht erste
           $page = $startpage-1;
           $api = new SwissUnihockey_Public(); 
           $details = $api->clubGames($season, $club_ID, $team_ID, $mode, array(
             'page' => $page
       )); 
      }

      
  // SwissUnihockey_Api_Public::log_me($details);

      $data = $details->data; 
      $header_DateTime = $data->headers[0]->text;
      $header_Location = $data->headers[1]->text;
      // $header_Leage = $data->headers[2]->text;
      $header_Home = $data->headers[2]->text;
      $header_Guest = $data->headers[3]->text;
      $header_Result = $data->headers[4]->text;
      //$header_Result = "Res.";
      $club_name = $data->title;
      $games = $data->regions[0]->rows;
      $attributes = $data->regions[0]->rows[0]->cells;
      
      $entries = count($games);

      $loop = FALSE;
      $tabs = $data->context->tabs;
      if ($tabs = "on") $loop = TRUE;
      $startpage = $data->context->page;
      $page = $startpage;

      $items = 0;
      $today = strtotime("now");
      $startdate = strtotime("-1 days",$start_date_us);
      $cTime = (SwissUnihockey_Api_Public::cacheTime() / 60)*$trans_Factor;
   
      $homeClass ="suhv-place";
      $html_head = "";

      error_reporting(E_ALL & ~E_NOTICE);
      while ($loop) {
      $i = 0;
      do {
            $game_id = $games[$i]->link->ids[0];

            $api_game = new SwissUnihockey_Public(); 
            $details_game = $api_game->gameDetails($game_id, array()); 

            $data_game = $details_game->data;
            //echo $details_game->data->title."<br>";
            $home_logo = $details_game->data->regions[0]->rows[0]->cells[0]->image->url;
            if (!stripos($home_logo,"https")) $home_logo = str_replace ("http","https",$home_logo);
            $home_logo_alt = $details_game->data->regions[0]->rows[0]->cells[0]->image->alt;
            //echo $home_logo."-link-H-".$home_logo_alt."<br>";
            $guest_logo = $details_game->data->regions[0]->rows[0]->cells[2]->image->url;
            if (!stripos($guest_logo,"https"))$guest_logo = str_replace ("http","https",$guest_logo);;
            $guest_logo_alt = $details_game->data->regions[0]->rows[0]->cells[2]->image->alt;
            //echo $guest_logo."-link-G-".$guest_logo_alt."<br>";

            $game_detail_link = "https://www.swissunihockey.ch/de/game-detail?game_id=".$game_id;
            $game_date = $games[$i]->cells[0]->text[0];
            $game_time = $games[$i]->cells[0]->text[1];
            if ($game_time != "???") {
              $game_location_name = $games[$i]->cells[1]->text[0]; 
              $game_location = $games[$i]->cells[1]->text[1]; 
              $game_map_x = $games[$i]->cells[1]->link->x;
              $game_map_y = $games[$i]->cells[1]->link->y;
            }
            else {
              $game_location_name = "";
              $game_location = ""; 
              $game_map_x = "";
              $game_map_y = "";
            }
            //$game_leage = $games[$i]->cells[2]->text[0]; 
            $game_homeclub = $games[$i]->cells[2]->text[0]; 
            $game_guestclub = $games[$i]->cells[3]->text[0]; 
            $game_result = $games[$i]->cells[4]->text[0];
            $linkGame_ID = $games[$i]->link->ids[0];
            $new_result = $game_result;
            $game_result_add = "";
            if (isset($games[$i]->cells[4]->text[1])) {$game_result_add = $games[$i]->cells[4]->text[1];}
            $game_home_result = substr($game_result,0,stripos($game_result,":"));
            $game_guest_result = substr($game_result,stripos($game_result,":")+1,strlen($game_result));
            $site_url = get_site_url();
            $site_display = substr($site_url,stripos($site_url,"://")+3);
          
            //Fehlerkorrektur für vom 7.1.2017
            if ($game_date=="today") $game_date="heute";
            if ($game_date=="yesterday") $game_date="gestern";

            if (($game_date=="heute") or ($game_date=="gestern"))  {
              if ($game_date=="heute")  { 
                $date_of_game = strtotime("today");
                $last_result = $last_games[$i]->cells[4]->text[0];
              }
              if ($game_date=="gestern") $date_of_game = strtotime("yesterday");
            }
            else{
             $date_parts = explode(".", $game_date); // dd.mm.yyyy in german
             $date_of_game = strtotime($date_parts[2]."-".$date_parts[1]."-".$date_parts[0]);
            }

            $game_maplink = "<a href=\"https://maps.google.ch/maps?q=".$game_map_y.",".$game_map_x."\""." target=\"_blank\" title= \"".$game_location_name."\">";

            $game_homeDisplay = $game_homeclub;
            $game_guestDisplay = $game_guestclub;

            if ($game_result == "")  { 
              $game_result = "vs.";
            }


            if (($items <= $n_Games) and ($date_of_game <= $end_date_us)) {

              if (($date_of_game > $startdate) and ($date_of_game <= $end_date_us) and ($linkGame_ID_before != $linkGame_ID)) {  
              
               $html_body .= "<div class=\"match-detail\">".
               "<div class=\"match-info\">".
               "<div class=\"match-headline\">"."<a href=\"".$game_detail_link."\" title=\"Matchtelegramm auf Swissunihockey\" >".$game_homeclub." vs. ".$game_guestclub."</a></div><div class=\"match-datetime\">".$game_date." - ".$game_time."</div>".
                "<div class=\"match-result\">".
                  "<div class=\"match-team-home\">"."<img title=\"".$game_homeclub."\" alt=\"".$game_homeclub."\" src=\"".$home_logo."\" >"."</div>".
                  "<div class=\"match-team-count\">"."<br />".$game_result."<div class=\"match-team-count-add\">".$game_result_add."</div></div>".
                  "<div class=\"match-team-guest\">"."<img title=\"".$game_guestclub."\" alt=\"".$game_guestclub."\" src=\"".$guest_logo."\" >"."</div>".
                "</div>".
                "<div class=\"match-location\">".$game_maplink.$game_location." / ".$game_location_name."</a></div>".
               "</div></div>";
               }
              else $items++;
            }
            else {
              $loop = FALSE;
            }
            $i++; 
            $linkGame_ID_before = $linkGame_ID;
         } while (($i < $entries) and ($loop));

         if ($data->slider->next == NULL) { $loop = FALSE; }// only this loop
         else {
           $page++; 
           if ($page >= ($startpage+10)) $loop = FALSE; // Don't Loop always. // max 10 Pages
           $api = new SwissUnihockey_Public(); 
           $api_calls++;
           $details = $api->clubGames($season, $club_ID, $team_ID, $mode, array(
              'page' => $page
           )); 
           $data = $details->data; 
           $games = $data->regions[0]->rows;
           $entries = count($games);
           // SwissUnihockey_Api_Public::log_me("club_getGames api-calls:". $api_calls." entries: ".$entries." items: ".$items." page: ".$page." n: ".$n_Games);
        } // end else
      } // end While
        // Report all errors
      error_reporting(E_ALL);

      $html_head .= $html_res;
      $html .= $html_head;
      $html .= $html_body;

   
     
      set_transient( $transient, $html, SwissUnihockey_Api_Public::cacheTime()*$trans_Factor );
      //output some debug string
      // error_log( 'api_club_getGames:');
      //error_log( print_r(strftime("%A - %H:%M")) );
    } //end If
    else { $html = $value; }
    return $html;
  }
/* ---------------------------------------------------------------------------------------------------- */
 public static function api_team_getPlayedGames($season, $club_ID, $club_shortname, $team_ID, $mode, $cache) {

    $transient = $club_ID.$team_ID."getPlayedGames".$season.$mode;
    $value = get_transient( $transient );
    $trans_Factor = 1;
    $my_club_name = $club_shortname;
    
    if (!$cache) $value = False;
    if (SwissUnihockey_Api_Public::suhvDown()){ $value = TRUE; }

    if ($value == False) {

      // SwissUnihockey_Api_Public::log_me(array('function' => 'team_getPlayedGames', 'season' => $season, 'club_ID' =>  $club_ID, 'team_ID' =>   $team_ID, 'mode' =>$mode));

      $html = "";
      $html_res = "";
      $html_body = "";

      $plugin_options = get_option( 'SUHV_WP_plugin_options' );
      $tablepress ='';
      if ((isset( $plugin_options['SUHV_css_tablepress']) == 1)) $tablepress = " tablepress";
      
      // echo "<br>".$season."<br>".$clubId;

      $api = new SwissUnihockey_Public(); 
      $details = $api->clubGames($season, $club_ID, $team_ID, $mode, array(
         'page' => 1
      )); 


      $data = $details->data; 
      $header_DateTime = $data->headers[0]->text;
      $header_Location = $data->headers[1]->text;
      $header_Home = $data->headers[2]->text;
      $header_Guest = $data->headers[3]->text;
      $header_Guest = "Gegner";
      $header_Result = $data->headers[4]->text;
      $header_Result = "Resultat";
      $club_name = $data->title;
      $games = $data->regions[0]->rows;
      $attributes = $data->regions[0]->rows[0]->cells;
      
      $homeClass ="suhv-place";
      $entries = count($games);
      $html_head = "<table class=\"suhv-table suhv-planned-games-full".$tablepress."\">\n";
      $html_head .= "<caption>".$data->title."</caption>";
      $html_head .= "<thead><tr><th class=\"suhv-date\">"."Datum, Zeit".
      "</th><th class=\"suhv-place\">".$header_Location.
      "</th><th class=\"suhv-opponent\">".$header_Guest."</th>";

      $loop = FALSE;
      $tabs = $data->context->tabs;
      if ($tabs = "on") $loop = TRUE;
      $startpage = $data->context->page;
      $page = $startpage;

      error_reporting(E_ALL & ~E_NOTICE);
      while ($loop) {
      $i = 0;
      do {
            $game_id = $games[$i]->link->ids[0];
            $game_detail_link = "https://www.swissunihockey.ch/de/game-detail?game_id=".$game_id;
            $game_date = $games[$i]->cells[0]->text[0];
            $game_time = $games[$i]->cells[0]->text[1];
            $game_location_name = $games[$i]->cells[1]->text[0]; 
            $game_location = $games[$i]->cells[1]->text[1]; 
            $game_map_x = $games[$i]->cells[1]->link->x;
            $game_map_y = $games[$i]->cells[1]->link->y; 
            $game_homeclub = $games[$i]->cells[2]->text[0]; 
            $game_guestclub = $games[$i]->cells[3]->text[0]; 
            $game_result = $games[$i]->cells[4]->text[0];
            $game_result_add = $games[$i]->cells[4]->text[1];
            $game_home_result = substr($game_result,0,stripos($game_result,":"));
            $game_guest_result = substr($game_result,stripos($game_result,":")+1,strlen($game_result));

            $game_maplink = "<a href=\"https://maps.google.ch/maps?q=".$game_map_y.",".$game_map_x."\""." target=\"_blank\" title= \"".$game_location_name."\">";
       
            $game_homeDisplay = $game_homeclub;

            $game_guestDisplay = $game_guestclub;
            $game_leage = str_replace ("Junioren", "",$game_leage);
            //$game_leage = str_replace ("Herren", "",$game_leage);
           
            if ($game_home_result == $game_guest_result) { $resultClass = 'suhv-draw';} else {$resultClass = 'suhv-result';}
            if ((substr_count($game_homeDisplay,$my_club_name)>=1) and (substr_count($club_name,$game_homeclub)>=1)){ 
              $game_Opponent = $game_guestDisplay; 
              if ($game_home_result > $game_guest_result) { $resultClass = 'suhv-win';} else {$resultClass = 'suhv-lose';}
            }
            if ((substr_count($game_guestDisplay,$my_club_name)>=1) and (substr_count($club_name,$game_guestclub)>=1)) {
              $game_Opponent = $game_homeDisplay;
              if ($game_guest_result > $game_home_result) { $resultClass = 'suhv-win';} else {$resultClass = 'suhv-lose';}
            }

            if ($game_result == "")  { 
              $resultClass = 'suhv-result';
              $items++;
            }
            if (($game_date=="heute") and ((substr_count($game_result,"*")!=0) or (substr_count($game_result,"-")!=0)))   {
              $resultClass = 'suhv-activ';
            } 

            if ($game_result != "") {
                $html_body .= "<tr". ($i % 2 == 1 ? ' class="alt"' : '') . "><td class=\"".$header_DateTime."\">".$game_date.", ".$game_time.
                "</td><td class=\"".$homeClass."\">".$game_maplink.$game_location_name." (".$game_location.")</a>".
                "</td><td class=\"suhv-opponent\">".$game_Opponent;
                if (($game_result != "")) {
                  $html_res = "<th class=\"suhv-result\">".$header_Result."</th>"; 
                  $html_body .= "</td><td class=\"".$resultClass."\"><a href=\"".$game_detail_link."\" title=\"Spieldetails\" >"."<strong>".$game_result."</strong><br>". $game_result_add."</a></td>";
                }
                $html_body .= "</tr>";
            }
            else {
              $loop = FALSE;
            }
            $i++; 

         } while (($i < $entries) and ($loop));

         if ($data->slider->next == NULL) { $loop = FALSE; }// only this loop
         else {
           $page++; 
           if ($page >= ($startpage+10)) $loop = FALSE; // Don't Loop always. // max 10 Pages
           $api = new SwissUnihockey_Public(); 
           $details = $api->clubGames($season, $club_ID, $team_ID, $mode, array(
              'page' => $page
           )); 
           $data = $details->data; 
           $games = $data->regions[0]->rows;
           $entries = count($games);
        } // end else
      } // end While
        // Report all errors
      error_reporting(E_ALL);
      $html_head .= $html_res;
      $html_head .= "</tr></thead><tbody>";
      $html .= $html_head;
      $html .= $html_body;
      $html .= "</tbody>";
      $html .= "</table>";

      set_transient( $transient, $html, SwissUnihockey_Api_Public::cacheTime()*$trans_Factor);
    }
    else { $html = $value; }
    return $html;
      
  }
/* ---------------------------------------------------------------------------------------------------- */
 public static function api_team_getGames($season, $club_ID, $club_shortname, $team_ID, $mode, $cache) {

    $transient = $club_ID.$team_ID."team_getGames".$season.$mode;
    $value = get_transient( $transient );
    $trans_Factor = 5;
    $my_club_name = $club_shortname;

    if (!$cache) $value = False;
    if (SwissUnihockey_Api_Public::suhvDown()){ $value = TRUE; }

    if ($value == False) {

      // SwissUnihockey_Api_Public::log_me(array('function' => 'team_getGames', 'season' => $season, 'club_ID' =>  $club_ID, 'team_ID' =>   $team_ID, 'mode' =>$mode));

      $html = "";
      $plugin_options = get_option( 'SUHV_WP_plugin_options' );
      $tablepress ='';
      if ((isset( $plugin_options['SUHV_css_tablepress']) == 1)) $tablepress = " tablepress";
      
      // echo "<br>".$season."<br>".$clubId;
      $page = 1;

      $api = new SwissUnihockey_Public(); 

      $details = $api->clubGames($season, $club_ID, $team_ID, $mode, array(
        
      )); 
      $data = $details->data; 

      SwissUnihockey_Api_Public::log_me(array('function' => 'league_team_getGames', 'season' => $season, 'club_ID' => $club_ID, 'team_ID' => $team_ID , 'mode' => $mode));


      $loop = FALSE;
      $tabs = $data->context->tabs;
      if ($tabs = "on") $loop = TRUE;

      $page = $data->context->page;
      $header_DateTime = $data->headers[0]->text;
      $header_Location = $data->headers[1]->text;
      $header_Home = $data->headers[2]->text;
      $header_Guest = $data->headers[3]->text;
      $header_Guest = "Gegner";
      $header_Result = $data->headers[4]->text;
      $club_name = $data->title;
      $games = $data->regions[0]->rows;
      $attributes = $data->regions[0]->rows[0]->cells;
  

      $html .= "<table class=\"suhv-table".$tablepress."\">\n";
      $html .= "<caption>".$data->title."</caption>";
      $html .= "<thead><tr><th class=\"suhv-date\">"."Datum, Zeit";
      $html .= "</th><th class=\"suhv-opponent\">".$header_Guest;
      $html .= "</th><th class=\"suhv-location\">".$header_Location;
      //$game_result = $games[0]->cells[4]->text[0];
      //if ($game_result != "")  
      //   $html .= "</th><th class=\"suhv-result\">".$header_Result."</th></tr></thead>";
      $html .= "</th></tr></thead>";
      $html .= "<tbody>";

      error_reporting(E_ALL & ~E_NOTICE); 
      $i = 0;
      $entries = count($games);
      while ($loop) {
       
        do {

            $game_date = $games[$i]->cells[0]->text[0];
            $game_time = $games[$i]->cells[0]->text[1];
            $game_location_name = $games[$i]->cells[1]->text[0]; 
            $game_location = $games[$i]->cells[1]->text[1]; 
            $game_map_x = $games[$i]->cells[1]->link->x;
            $game_map_y = $games[$i]->cells[1]->link->y;
            $game_homeclub = $games[$i]->cells[2]->text[0]; 
            $game_guestclub = $games[$i]->cells[3]->text[0]; 
            $game_result = $games[$i]->cells[4]->text[0];
            $game_result_add = $games[$i]->cells[4]->text[1];
            $game_home_result = substr($game_result,0,stripos($game_result,":"));
            $game_guest_result = substr($game_result,stripos($game_result,":")+1,strlen($game_result));

            $game_maplink = "<a href=\"https://maps.google.ch/maps?q=".$game_map_y.",".$game_map_x."\""." target=\"_blank\" title= \"".$game_location_name."\">";
       
            $game_homeDisplay = $game_homeclub;
            $game_guestDisplay = $game_guestclub;
            $game_Diplay = "";
            $homeClass ="suhv-place";
            //$game_leage = str_replace ("Herren", "",$game_leage);
            
            if ($game_home_result == $game_guest_result) { $resultClass = 'suhv-draw';} else {$resultClass = 'suhv-result';}
            if (substr_count($game_homeDisplay,$my_club_name)>=1) { 
              $game_Display = $game_guestDisplay; 
              if ($game_home_result > $game_guest_result) { $resultClass = 'suhv-win';} else {$resultClass = 'suhv-lose';}
            }
            if (substr_count($game_guestDisplay,$my_club_name)>=1) {
              $game_Display = $game_homeclub;
              if ($game_guest_result > $game_home_result) { $resultClass = 'suhv-win';} else {$resultClass = 'suhv-lose';}
            }
            if ($game_result == "")  {
              $html .= "<tr". ($i % 2 == 1 ? ' class="alt"' : '') ."><td class=\"".$header_DateTime."\">".$game_date.", ".$game_time;
              $html .= "</td><td>".$game_Display;
              $html .= "</td><td class=\"".$homeClass."\">".$game_maplink.$game_location_name." (".$game_location.")"."</a>";
              //$html .= "</td><td class=\"suhv-result\"><strong>".$game_result."</strong><br>". $game_result_add."</td>".
              $html .= "<td></tr>";
            }
            $i++; 

        } while ($i < $entries);

        if ($data->slider->next == NULL) { $loop = FALSE; }// only this loop
        else {
          $page++; 
          if ($page > 10) $loop = FALSE; // Don't Loop always.

          $api = new SwissUnihockey_Public(); 
          $details = $api->clubGames($season, $club_ID, $team_ID, $mode, array(
             'page' => $page
           )); 
           $data = $details->data; 
           $games = $data->regions[0]->rows;
           $i = 0;
           $entries = count($games);
        } // end else
      } // end While
        // Report all errors
      error_reporting(E_ALL);
      $html .= "</tbody>";
      $html .= "</table>";
      set_transient( $transient, $html, SwissUnihockey_Api_Public::cacheTime()*$trans_Factor );
    }
    else { $html = $value; }
    return $html;
      
  }


/* ---------------------------------------------------------------------------------------------------- */

  public static function api_getTeamTable($season, $club_ID, $team_ID, $mode, $cache) {

    $transient = $club_ID.$team_ID."getTeamTable".$season;
    $value = get_transient( $transient );
    $trans_Factor = 5;
    
// Sample: https://api-v2.swissunihockey.ch/api/rankings?&team_id=427913&club_id=423403&season=2015

    if (!$cache) $value = False;
    if (SwissUnihockey_Api_Public::suhvDown()){ $value = TRUE; }

    if ($value == False) {

      // SwissUnihockey_Api_Public::log_me(array('function' => 'getTeamTable', 'season' => $season, 'club_ID' =>  $club_ID, 'team_ID' =>   $team_ID, 'mode' =>$mode));

      $html = "";
      $plugin_options = get_option( 'SUHV_WP_plugin_options' );
      $tablepress ='';
      if ((isset( $plugin_options['SUHV_css_tablepress']) == 1)) $tablepress = " tablepress";

      // echo "<br>".$season."-".$club_ID."-".$team_ID;

      $api = new SwissUnihockey_Public();
      $version_info = $api->version();
 
      $details = $api->rankings($season, $club_ID, $team_ID, $mode, array(
        
      )); 

      $data = $details->data; 

      error_reporting(E_ALL & ~E_NOTICE);
      $headerCount = count($data->headers);
      //echo "<br> HEADER-Count".$headerCount;
      $smallTable = FALSE;
      if ($headerCount < 11) $smallTable = TRUE;
      
      $header_Rank = $data->headers[0]->text;
      $header_Team = $data->headers[2]->text;
      $header_Sp = $data->headers[3]->text;
      $header_S = $data->headers[4]->text;
      if ($smallTable) {
        $header_U = $data->headers[5]->text;
        $header_N = $data->headers[6]->text;
        $header_T = $data->headers[7]->text;
        $header_T = "Tore";
        $header_TD = $data->headers[8]->text;
        $header_P = $data->headers[9]->text;
        $header_P = "Pt.";
      }
      else{
        $header_SnV = $data->headers[5]->text;
        $header_NnV = $data->headers[6]->text;
        $header_N = $data->headers[7]->text;
        $header_T = $data->headers[8]->text;
        $header_T = "Tore";
        $header_TD = $data->headers[9]->text;
        $header_P = $data->headers[10]->text;
        $header_P = "Pt.";
      }
      $Table_title = $data->title;
      //* $rankings = $data->regions[0]->rows;
      $rankings = $data->regions[0]->rows;
    
      $entries = count($rankings);

      if (!$cache) {
         $view_cache = "<br> cache = off / Team-ID: ".$team_ID; 
        } else {$view_cache ="";
      }
     
      $html .= "<table class=\"suhv-table ".$tablepress."\">";
      $html .= "<caption>".$data->title.$view_cache."</caption>";
      $html .= "<thead>".       
        "<tr><th class=\"suhv-rank\"><abbr title=\"Rang\">".$header_Rank."</abbr>".
        "</th><th class=\"suhv-team\"><abbr title=\"Team\">".$header_Team."</abbr>".
        "</th><th class=\"suhv-games\"><abbr title=\"Spiele\">".$header_Sp."</abbr>".
        "</th><th class=\"suhv-wins\"><abbr title=\"Siege\">".$header_S."</abbr>";
      if ($smallTable) {
        $html .= "</th><th class=\"suhv-even\"><abbr title=\"Spiele unentschieden\">".$header_U."</abbr>".
        "</th><th class=\"suhv-lost\"><abbr title=\"Niederlagen\">".$header_N."</abbr>".
        "</th><th class=\"suhv-scored\"><abbr title=\"Torverhältnis\">".$header_T."</abbr>".
        "</th><th class=\"suhv-diff\"><abbr title=\"Tordifferenz\">".$header_TD."</abbr>".
        "</th><th class=\"suhv-points\"><abbr title=\"Punkte\">".$header_P."</abbr>";
      }
      else{
        $html .= "</th><th class=\"suhv-ties\"><abbr title=\"Siege nach Verlängerung\">".$header_SnV."</abbr>".
        "</th><th class=\"suhv-defeats\"><abbr title=\"Niederlagen nach Verlängerung\">".$header_NnV."</abbr>".
        "</th><th class=\"suhv-lost\"><abbr title=\"Niederlagen\">".$header_N."</abbr>".
        "</th><th class=\"suhv-scored\"><abbr title=\"Torverhältnis\">".$header_T."</abbr>".
        "</th><th class=\"suhv-diff\"><abbr title=\"Tordifferenz\">".$header_TD."</abbr>".
        "</th><th class=\"suhv-points\"><abbr title=\"Punkte\">".$header_P."</abbr>";
      }
      $html .= "</th></tr></thead>";
      $html .= "<tbody>";
      

      $i = 0;

      do {
           $ranking_TeamID = $data->regions[0]->rows[$i]->data->team->id;
           $ranking_TeamName = $data->regions[0]->rows[$i]->data->team->name;

           $ranking_Rank = $data->regions[0]->rows[$i]->cells[0]->text[0];
           $ranking_Team = $data->regions[0]->rows[$i]->cells[2]->text[0];
           $ranking_Sp = $data->regions[0]->rows[$i]->cells[3]->text[0];
           $ranking_S = $data->regions[0]->rows[$i]->cells[4]->text[0];
           if ($smallTable) {
             $ranking_U = $data->regions[0]->rows[$i]->cells[5]->text[0];
             $ranking_N = $data->regions[0]->rows[$i]->cells[6]->text[0];
             $ranking_T = $data->regions[0]->rows[$i]->cells[7]->text[0];
             $ranking_TD = $data->regions[0]->rows[$i]->cells[8]->text[0];
             $ranking_P = $data->regions[0]->rows[$i]->cells[9]->text[0];
           }
           else{
             $ranking_SnV = $data->regions[0]->rows[$i]->cells[5]->text[0];
             $ranking_NnV = $data->regions[0]->rows[$i]->cells[6]->text[0];
             $ranking_N = $data->regions[0]->rows[$i]->cells[7]->text[0];
             $ranking_T = $data->regions[0]->rows[$i]->cells[8]->text[0];
             $ranking_TD = $data->regions[0]->rows[$i]->cells[9]->text[0];
             $ranking_P = $data->regions[0]->rows[$i]->cells[10]->text[0];
           }
           if ($team_ID == $ranking_TeamID) { $tr_class = 'suhv-my-team';} else {$tr_class = '';}

           $html .= "<tr class=\"".$tr_class.($i % 2 == 1 ? ' alt' : '')."\">".
           "<td class=\"suhv-rank\">".$ranking_Rank.
           "</td><td class=\"suhv-team\">".$ranking_Team.
           "</td><td class=\"suhv-games\">".$ranking_Sp.
           "</td><td class=\"suhv-wins\">".$ranking_S;
           if ($smallTable) {
             $html .= "</td><td class=\"suhv-ties\">".$ranking_U.
             "</td><td class=\"suhv-lost\">".$ranking_N.
             "</td><td class=\"suhv-scored\">".$ranking_T.
             "</td><td class=\"suhv-diff\">".$ranking_TD.
             "</td><td class=\"suhv-points\">".$ranking_P;
           }
           else{
             $html .= "</td><td class=\"suhv-ties\">".$ranking_SnV.
             "</td><td class=\"suhv-defeats\">".$ranking_NnV.
             "</td><td class=\"suhv-lost\">".$ranking_N.
             "</td><td class=\"suhv-scored\">".$ranking_T.
             "</td><td class=\"suhv-diff\">".$ranking_TD.
             "</td><td class=\"suhv-points\">".$ranking_P;
           }
           $html .= "</td></tr>"; 
           $i++; 
        } while ($i < $entries);
        // Report all errors
        error_reporting(E_ALL);

      $html .= "</tbody>";
      $html .= "</table>";

      set_transient( $transient, $html,  SwissUnihockey_Api_Public::cacheTime()*$trans_Factor );
    }
    else { $html = $value; }
    return $html;
   
      
    }

    /* ---------------------------------------------------------------------------------------------------- */
  public static function api_getTeamRank($season, $club_ID, $team_ID, $mode, $cache) {

    $transient = $club_ID.$team_ID."getTeamRank".$season;
    $value = get_transient( $transient );
    $trans_Factor = 3;
    
    if (!$cache) $value = False;
    if (SwissUnihockey_Api_Public::suhvDown()){ $value = TRUE; }

    if ($value == False) {

      // SwissUnihockey_Api_Public::log_me(array('function' => 'getTeamRank', 'season' => $season, 'club_ID' =>  $club_ID, 'team_ID' =>   $team_ID, 'mode' =>$mode));

      $html = "";
      $plugin_options = get_option( 'SUHV_WP_plugin_options' );
      $tablepress ='';
      if ((isset( $plugin_options['SUHV_css_tablepress']) == 1)) $tablepress = " tablepress";
  
      // echo "<br>".$season."-".$club_ID."-".$team_ID;

      $api = new SwissUnihockey_Public();
      $version_info = $api->version();
 
      $details = $api->rankings($season, $club_ID, $team_ID, $mode, array(
        
      )); 

      $data = $details->data; 
     
      $header_Rank = $data->headers[0]->text;
      $header_Rank = "Rang";
      $header_Team = $data->headers[2]->text;
      $header_P = $data->headers[9]->text; // Points
      $header_P = "Punkte";
      $Table_title = $data->title;
      //* $rankings = $data->regions[0]->rows;
      if (isset($data->regions[0]->rows)) {
          $rankings = $data->regions[0]->rows;
          $entries =  count($rankings);
      }
      else $entries = 0;
      
      $headerCount = count($data->headers);


      if (!$cache) {
         $view_cache = "<br> cache = off / Team-ID: ".$team_ID; 
        } else {$view_cache ="";
      }
     

      $html .= "<table class=\"suhv-table".$tablepress."\">";
      $html .= "<caption>".$data->title.$view_cache."</caption>";
      $html .= "<thead>".
           "<tr><th class=\"suhv-rank\">".$header_Rank.
           "</th><th class=\"suhv-team\">".$header_Team.
           "</th><th class=\"suhv-points\">".$header_P.
           "</th></tr></thead>";
      $html .= "<tbody>";
      
      error_reporting(E_ALL & ~E_NOTICE);

      $smallTable = FALSE;
      if ($headerCount < 10) $smallTable = TRUE;

      $i = 0;
   
      do {
           $ranking_TeamID = $rankings[$i]->data->team->id;
           $ranking_TeamName = $rankings[$i]->data->team->name;

           $ranking_Rank = $rankings[$i]->cells[0]->text[0];
           $ranking_Team = $rankings[$i]->cells[2]->text[0];
           if ($smallTable) {
            $ranking_P = $rankings[$i]->cells[9]->text[0]; //Points
           }
           else {
            $ranking_P = $rankings[$i]->cells[10]->text[0]; // Points
           }
           if ($team_ID == $ranking_TeamID) { $tr_class = 'suhv-my-team';} else {$tr_class = '';}

           $html .= "<tr class=\"".$tr_class.($i % 2 == 1 ? ' alt' : '')."\">".
           "<td class=\"suhv-rank\">".$ranking_Rank.
           "</td><td class=\"suhv-team\">".$ranking_Team.
           "</td><td class=\"suhv-points\">".$ranking_P.
           "</td></tr>"; 
           $i++; 
        } while ($i < $entries);
        // Report all errors
      error_reporting(E_ALL);

      $html .= "</tbody>";
      $html .= "</table>";
      set_transient( $transient, $html, SwissUnihockey_Api_Public::cacheTime()*$trans_Factor);
    }
    else { $html = $value; }
    return $html;
   
      
  }



/* ---------------------------------------------------------------------------------------------------- */
 public static function api_league_getGames($season, $league, $game_class, $group, $round, $mode, $cache) {


    $transient = $league.$game_class.$group."league_getGames".$season.$mode;
    
    $value = get_transient( $transient );
    // $value = FALSE;
   
    $maxloop = 20;
    $loopcnt = 1;
    $trans_Factor = 12;
    $ActivRound = "";
    $lastActivRound = "";
    
    if ($league == 21) {$small = TRUE; } 
    else {$small = FALSE; }

    if (!$cache) $value = False;
    if (SwissUnihockey_Api_Public::suhvDown()){ $value = TRUE; }

    if ($value == False) {

      // SwissUnihockey_Api_Public::log_me(array('function' => 'league_getGames', 'season' => $season, 'league' => $league, 'game_class' => $game_class, 'group' => $group, 'round' => $round, 'mode' => $mode));

      $html = "";
      $plugin_options = get_option( 'SUHV_WP_plugin_options' );
      $tablepress ='';
      if ((isset( $plugin_options['SUHV_css_tablepress']) == 1)) $tablepress = " tablepress";

      $api = new SwissUnihockey_Public(); 

      $details = $api->games(array(
        'season' => $season, 'league' => $league, 'game_class' => $game_class, 'round' => $round, 'mode' => $mode, 'group' => $group, 
      )); 
      $data = $details->data; 


      $loop = FALSE;
      $nextround = $data->slider->next->set_in_context->round;
      //  echo "<br>Nextround:".$nextround."<br>";

      if ($data->slider->next <> NULL) $loop = TRUE;

      $round = $data->context->round;
      $header_DateTime = $data->headers[0]->text;
      $header_Location = $data->headers[1]->text;
      $header_Home = $data->headers[2]->text;
      $header_Home = "Heimteam";
      $header_Guest = $data->headers[6]->text;
      $header_Guest = "Gastteam";
      $header_Result = $data->headers[7]->text;
      $round_Title = $data->slider->text;
      $games = $data->regions[0]->rows;
      $game_location_name = $games[0]->cells[1]->text[0]; 

      $game_map_x = $games[0]->cells[1]->link->x;
      $game_map_y = $games[0]->cells[1]->link->y;


      $cTime = (SwissUnihockey_Api_Public::cacheTime() / 60)*$trans_Factor;

      $game_maplink = "<a href=\"https://maps.google.ch/maps?q=".$game_map_y.",".$game_map_x."\""." target=\"_blank\" title=\"".$game_location_name."\">";
     
      $ActivRound = trim(substr($round_Title,0,strpos($round_Title,'/')));
      $ActivRound = str_replace(' ', '_', $ActivRound);
      $html .= '<div class="suhv-round-link"><a id="ActivRound" href="#LastGame">'.__('Zur aktuellen Runde','SUHV-API-2').'</a></div>';
      $html .= '<div class="suhv-round-anchor"><a id="'.$ActivRound.'"></a></div>';
      if ($small) { $html .= "<h3>".$round_Title."&nbsp;&nbsp;".$game_maplink.$game_location_name."</a></h3>"; } 
      else  {
        $html .= "<h3>".$round_Title."</h3>";
      }
      // $html .= "<h3>".$round_Title."&nbsp;&nbsp;".$game_maplink.$game_location_name."</a></h3>";

      $html .= "<table class=\"suhv-table suhv-league".$tablepress."\">\n";
      // $html .= "<caption>timestamp: ".strftime("%H:%M")." - caching: ".$cTime." min.</caption>";
      // $html .= "<caption><h3>".$round_Title."  (".$game_maplink.$game_location_name."</a>)</h3></caption>";
      $html .= "<thead><tr><th class=\"suhv-league suhv-date\">"."Datum, Zeit";
      if  (!$small) {$html .= "</th><th class=\"suhv-league suhv-place\">".$header_Location;}
      $html .= "</th><th class=\"suhv-league suhv-opponent\">".$header_Home;
      $html .= "</th><th class=\"suhv-league suhv-opponent\">".$header_Guest;
      $html .= "</th><th class=\"suhv-league suhv-result\">".$header_Result."</th></tr></thead>";
      $html .= "<tbody>";

      error_reporting(E_ALL & ~E_NOTICE); 
      $i = 0;
      $entries = count($games);
   
      while ($loop) {
        
        do {

            $game_date_time = $games[$i]->cells[0]->text[0];
            $game_location_name = $games[$i]->cells[1]->text[0]; 
            $game_map_x = $games[$i]->cells[1]->link->x;
            $game_map_y = $games[$i]->cells[1]->link->y;
            $game_homeclub = $games[$i]->cells[2]->text[0]; 
            $game_guestclub = $games[$i]->cells[6]->text[0]; 
            $game_result = $games[$i]->cells[7]->text[0];
            if ($game_result == "") $game_result = "N/A";
  
            $game_home_result = substr($game_result,0,stripos($game_result,":"));
            $game_guest_result = substr($game_result,stripos($game_result,":")+1,strlen($game_result));

            $game_maplink = "<a href=\"https://maps.google.ch/maps?q=".$game_map_y.",".$game_map_x."\""." target=\"_blank\" title= \"".$game_location_name."\">";
       
            $game_homeDisplay = $game_homeclub;
            $game_guestDisplay = $game_guestclub;
            $game_Diplay = "";

              $html .= "<tr ". ($i % 2 == 1 ? 'class="alt odd"' : 'class="even"') ."><td class=\"suhv-league suhv-date\">".$game_date_time;
              if (!$small) { $html .= "</td><td class=\"suhv-league suhv-place\">".$game_maplink.$game_location_name."</a>"; } 
              $html .= "</td><td class=\"suhv-league suhv-opponent\">".$game_homeclub;
              $html .= "</td><td class=\"suhv-league suhv.opponent\">".$game_guestclub;
              $html .= "</td><td class=\"suhv-league suhv-result\">".$game_result."</td>";
              $html .= "</tr>";
            if ($game_result!="N/A") $LastActivRound = $ActivRound;
            $i++; 

        } while ($i < $entries);
        $html .= "</tbody>";
        $html .= "</table>"; 
        $loopcnt++;

        if (($data->slider->next == NULL) or ($loopcnt > $maxloop)) { $loop = FALSE; }// only this loop
        else {
          //echo '<br>next loop<br>';
          $api = new SwissUnihockey_Public(); 

          $details = $api->games(array(
            'season' => $season, 'league' => $league, 'game_class' => $game_class, 'round' => $nextround, 'mode' => $mode, 'group' => $group, 
          )); 

          $data = $details->data; 
          $nextround = $data->slider->next->set_in_context->round;
          //    echo "<br>Nextround:".$nextround."<br>";
          $i = 0;
          $entries = count($games);

          $round_Title = $data->slider->text;
          $games = $data->regions[0]->rows;
          $game_location_name = $games[0]->cells[1]->text[0]; 

          $game_map_x = $games[0]->cells[1]->link->x;
          $game_map_y = $games[0]->cells[1]->link->y;

          $game_maplink = "<a href=\"https://maps.google.ch/maps?q=".$game_map_y.",".$game_map_x."\""." target=\"_blank\" title= \"".$game_location_name."\">";
          $ActivRound = trim(substr($round_Title,0,strpos($round_Title,'/')));
          $ActivRound = str_replace(' ', '_', $ActivRound);
          $html .= '<div class="suhv-round-anchor"><a id="'.$ActivRound.'"></a></div>';
          if ($small) { $html .= "<h3>".$round_Title."&nbsp;&nbsp;".$game_maplink.$game_location_name."</a></h3>"; } 
          else  {
            $html .= "<h3>".$round_Title."</h3>";
          }

          $html .= "<table class=\"suhv-table suhv-league".$tablepress."\" >\n";
          $html .= "<thead><tr><th class=\"suhv-league suhv-date\" >"."Datum, Zeit";
          if (!$small) {$html .= "</th><th class=\"suhv-league  suhv-place\" >".$header_Location;}
          $html .= "</th><th class=\"suhv-league suhv-opponent\" >".$header_Home;
          $html .= "</th><th class=\"suhv-league suhv-opponent\" >".$header_Guest;
          $html .= "</th><th class=\"suhv-league suhv-result\" >".$header_Result."</th></tr></thead>";
          $html .= "<tbody>";
           
        } // end else
      } // end While
      $html .= '<script>document.getElementById("ActivRound").href = "#'.$LastActivRound.'"</script>';
        // Report all errors
      error_reporting(E_ALL);
    
      set_transient( $transient, $html, SwissUnihockey_Api_Public::cacheTime()*$trans_Factor );
    }
    else { $html = $value; }
    return $html;
      
  }


/* ---------------------------------------------------------------------------------------------------- */
 public static function api_league_getWeekend($season, $league, $game_class, $group, $round, $mode, $cache) {


    $weekend = TRUE;
    $weekend_games = FALSE;
    $weekendDays = SwissUnihockey_Api_Public::nearWeekend();
    $date_description = "Liga-Spiele vom Wochenende";
    $start_date_us = $weekendDays["Freitag"];
    $end_date_us = $weekendDays["Sonntag"];
    $start_date = date("d.m.Y",$start_date_us);
    $end_date = date("d.m.Y",$end_date_us);
    $html_head = "";

    $transient = $league.$game_class.$group."league_getWeekend".$season.$mode;
    
    $value = get_transient( $transient );
    // $value = FALSE;
   
    $maxloop = 20;
    $loopcnt = 1;
    $trans_Factor = 12;
    $ActivRound = "";
    $lastActivRound = "";
    $loop = TRUE;
    

    if ($league == 21) {$small = TRUE; } 
    else {$small = FALSE; }

    if (!$cache) $value = False;
    if (SwissUnihockey_Api_Public::suhvDown()){ $value = TRUE; }

    if ($value == False) {

      // SwissUnihockey_Api_Public::log_me(array('function' => 'league_getWeekend', 'season' => $season, 'league' => $league, 'game_class' => $game_class, 'group' => $group, 'round' => $round, 'mode' => $mode));

      $html = "";
      $plugin_options = get_option( 'SUHV_WP_plugin_options' );
      $tablepress ='';
      if ((isset( $plugin_options['SUHV_css_tablepress']) == 1)) $tablepress = " tablepress";


      $api = new SwissUnihockey_Public(); 

      // no 'round' => $round,
      $details = $api->games(array(
        'season' => $season, 'league' => $league, 'game_class' => $game_class,  'mode' => $mode, 'group' => $group, 
      )); 
      $data = $details->data; 
      $round = $data->slider->prev->set_in_context->round;

      if (isset($data->slider->next->set_in_context->round)) {
            $nextround = $data->slider->next->set_in_context->round;
            $round = $nextround +1; 
          }
      else $nextround = $round;

      $header_DateTime = $data->headers[0]->text;
      $header_Location = $data->headers[1]->text;
      $header_Home = $data->headers[2]->text;
      $header_Home = "Heimteam";
      $header_Guest = $data->headers[6]->text;
      $header_Guest = "Gastteam";
      $header_Result = $data->headers[7]->text;
      $round_Title = $data->slider->text;
      $games = $data->regions[0]->rows;
      $game_location_name = $games[0]->cells[1]->text[0]; 

      $game_map_x = $games[0]->cells[1]->link->x;
      $game_map_y = $games[0]->cells[1]->link->y;

      $game_date_time = $games[0]->cells[0]->text[0];

      $date_of_game = strtotime("today");

      $game_date = substr($game_date_time,0,stripos($game_date_time," "));
      if (($game_date == "heute") or ($game_date == "gestern"))  {
         if ($game_date == "heute") $date_of_game = strtotime("today");
         if ($game_date == "gestern") $date_of_game = strtotime("yesterday");
      }
      else{
         $date_parts = explode(".",$game_date); // dd.mm.yyyy in german
         $date_of_game = strtotime($date_parts[2]."-".$date_parts[1]."-".$date_parts[0]);
      }

      $cTime = (SwissUnihockey_Api_Public::cacheTime() / 60)*$trans_Factor;

      $game_maplink = "<a href=\"https://maps.google.ch/maps?q=".$game_map_y.",".$game_map_x."\""." target=\"_blank\" title=\"".$game_location_name."\">";
     
      $ActivRound = trim(substr($round_Title,0,strpos($round_Title,'/')));
      $ActivRound = str_replace(' ', '_', $ActivRound);
      //$html .= '<div class="suhv-round-link"><a id="ActivRound" href="#LastGame">'.__('Zur aktuellen Runde','SUHV-API-2').'</a></div>';
      //$html .= '<div class="suhv-round-anchor"><a id="'.$ActivRound.'"></a></div>';
      if (($date_of_game >= $start_date_us) and ($date_of_game <= $end_date_us)) {
        $loop = TRUE;
        if ($small) { $html .= "<h3>".$round_Title."&nbsp;&nbsp;".$game_maplink.$game_location_name."</a></h3>"; } 
        else  {
          $html .= "<h3>".$round_Title."</h3>";
        }
        // $html .= "<h3>".$round_Title."&nbsp;&nbsp;".$game_maplink.$game_location_name."</a></h3>";

        $html .= "<table class=\"suhv-table suhv-league".$tablepress."\">\n";
        if ($weekend) 
          $html_head .= "<caption>".$date_description." ".$start_date." bis ".$end_date."</caption>";
        else
          $html_head .= "<caption>"."Spiele vom ".$start_date." bis ".$end_date."</caption>";
        $html .= "<thead><tr><th class=\"suhv-league suhv-date\">"."Datum, Zeit";
        if  (!$small) {$html .= "</th><th class=\"suhv-league suhv-place\">".$header_Location;}
        $html .= "</th><th class=\"suhv-league suhv-opponent\">".$header_Home;
        $html .= "</th><th class=\"suhv-league suhv-opponent\">".$header_Guest;
        $html .= "</th><th class=\"suhv-league suhv-result\">".$header_Result."</th></tr></thead>";
        $html .= "<tbody>";
      }
      error_reporting(E_ALL & ~E_NOTICE); 
      $i = 0;
      $entries = count($games);
   
      while ($loop) {
        
        do {

            $game_date_time = $games[$i]->cells[0]->text[0];
            $game_location_name = $games[$i]->cells[1]->text[0]; 
            $game_map_x = $games[$i]->cells[1]->link->x;
            $game_map_y = $games[$i]->cells[1]->link->y;
            $game_homeclub = $games[$i]->cells[2]->text[0]; 
            $game_guestclub = $games[$i]->cells[6]->text[0]; 
            $game_result = $games[$i]->cells[7]->text[0];
            if ($game_result == "") $game_result = "N/A";
  
            $game_home_result = substr($game_result,0,stripos($game_result,":"));
            $game_guest_result = substr($game_result,stripos($game_result,":")+1,strlen($game_result));

            $game_maplink = "<a href=\"https://maps.google.ch/maps?q=".$game_map_y.",".$game_map_x."\""." target=\"_blank\" title= \"".$game_location_name."\">";
       
            $game_homeDisplay = $game_homeclub;
            $game_guestDisplay = $game_guestclub;
            $game_Diplay = "";

            $date_of_game = strtotime("today");
            $game_date = substr($game_date_time,0,stripos($game_date_time," "));
            if (($game_date == "heute") or ($game_date == "gestern"))  {
               if ($game_date == "heute") $date_of_game = strtotime("today");
               if ($game_date == "gestern") $date_of_game = strtotime("yesterday");
            }
            else{
               $date_parts = explode(".",$game_date); // dd.mm.yyyy in german
               $date_of_game = strtotime($date_parts[2]."-".$date_parts[1]."-".$date_parts[0]);
            }
            if (($date_of_game >= $start_date_us) and ($date_of_game <= $end_date_us)) {
              $html .= "<tr ". ($i % 2 == 1 ? 'class="alt odd"' : 'class="even"') ."><td class=\"suhv-league suhv-date\">".$game_date_time;
              if (!$small) { $html .= "</td><td class=\"suhv-league suhv-place\">".$game_maplink.$game_location_name."</a>"; } 
              $html .= "</td><td class=\"suhv-league suhv-opponent\">".$game_homeclub;
              $html .= "</td><td class=\"suhv-league suhv.opponent\">".$game_guestclub;
              $html .= "</td><td class=\"suhv-league suhv-result\">".$game_result."</td>";
              $html .= "</tr>";
              if ($game_result!="N/A") $LastActivRound = $ActivRound;
            }
            else $i = $entries; // exit
            $i++; 
        } while ($i < $entries);
        if (($date_of_game >= $start_date_us) or ($date_of_game <= $end_date_us)) {
          $html .= "</tbody>";
          $html .= "</table>"; 
          $weekend_games = TRUE;
        }
        $loopcnt++;

        if (($data->slider->next == NULL) or ($loopcnt > $maxloop)) { $loop = FALSE; }// only this loop
        else {
          //echo '<br>next loop<br>';
          $api = new SwissUnihockey_Public(); 

          $details = $api->games(array(
            'season' => $season, 'league' => $league, 'game_class' => $game_class, 'round' => $nextround, 'mode' => $mode, 'group' => $group, 
          )); 

         SwissUnihockey_Api_Public::log_me(array('function' => 'league_getWeekend_sub', 'season' => $season, 'league' => $league, 'game_class' => $game_class, 'group' => $group, 'round' => $nextround, 'mode' => $mode));

          $data = $details->data; 
          if (isset($data->slider->next->set_in_context->round)) 
            $nextround = $data->slider->next->set_in_context->round;

          $i = 0;
          $entries = count($games);

          $round_Title = $data->slider->text;
          $games = $data->regions[0]->rows;
          $game_location_name = $games[0]->cells[1]->text[0]; 
          $game_date_time = $games[0]->cells[0]->text[0];

          $game_map_x = $games[0]->cells[1]->link->x;
          $game_map_y = $games[0]->cells[1]->link->y;

          $date_of_game = strtotime("today");
          $game_date = substr($game_date_time,0,stripos($game_date_time," "));
          if (($game_date == "heute") or ($game_date == "gestern"))  {
             if ($game_date == "heute") $date_of_game = strtotime("today");
             if ($game_date == "gestern") $date_of_game = strtotime("yesterday");
          }
          else{
             $date_parts = explode(".",$game_date); // dd.mm.yyyy in german
             $date_of_game = strtotime($date_parts[2]."-".$date_parts[1]."-".$date_parts[0]);
          }

          if ($date_of_game > $end_date_us) { $loop = FALSE;  // > nicht >= 
          } // exit 

          $game_maplink = "<a href=\"https://maps.google.ch/maps?q=".$game_map_y.",".$game_map_x."\""." target=\"_blank\" title= \"".$game_location_name."\">";
          $ActivRound = trim(substr($round_Title,0,strpos($round_Title,'/')));
          $ActivRound = str_replace(' ', '_', $ActivRound);
          $html .= '<div class="suhv-round-anchor"><a id="'.$ActivRound.'"></a></div>';
          if (($date_of_game >= $start_date_us) and ($date_of_game <= $end_date_us)) {
            if ($small) { $html .= "<h3>".$round_Title."&nbsp;&nbsp;".$game_maplink.$game_location_name."</a></h3>"; } 
            else  {
              $html .= "<h3>".$round_Title."</h3>";
            }

            $html .= "<table class=\"suhv-table suhv-league".$tablepress."\" >\n";
            $html .= "<thead><tr><th class=\"suhv-league suhv-date\" >"."Datum, Zeit";
            if (!$small) {$html .= "</th><th class=\"suhv-league  suhv-place\" >".$header_Location;}
            $html .= "</th><th class=\"suhv-league suhv-opponent\" >".$header_Home;
            $html .= "</th><th class=\"suhv-league suhv-opponent\" >".$header_Guest;
            $html .= "</th><th class=\"suhv-league suhv-result\" >".$header_Result."</th></tr></thead>";
            $html .= "<tbody>";
          }
        } // end else
      } // end While
      if (!$weekend_games ) {
         $html .= 'Keine Spiele in dieser Liga am Wochenende '.date("d.m.Y",$start_date_us)." - ".date("d.m.Y",$end_date_us)."<br />";
      }
      
      //$html .= '<script>document.getElementById("ActivRound").href = "#'.$LastActivRound.'"</script>';
        // Report all errors
      error_reporting(E_ALL);
    
      set_transient( $transient, $html, SwissUnihockey_Api_Public::cacheTime()*$trans_Factor );
    }
    else { $html = $value; }
    return $html;
      
  }




    /* ---------------------------------------------------------------------------------------------------- */
  public static function api_getPlayer($player_id, $sponsor_name, $sponsor_sub, $sponsor_logo, $sponsor_link, $sponsor_link_title, $cache) {
 
    $transient = $player_id."getPlayer";
    $value = get_transient( $transient );
    $trans_Factor = 1;

    if (!$cache) $value = False;
    //echo "<br> PLAYER ID : ".$player_id."<br>" ;
    if (SwissUnihockey_Api_Public::suhvDown()){ $value = TRUE; }

    if ($value == False) {

      // SwissUnihockey_Api_Public::log_me(array('function' => 'getPlayer', 'player_id' =>  $player_id, 'sponsor_name' => $sponsor_name, 'sponsor_sub' => $sponsor_sub, 'sponsor_link' => $sponsor_link));

      $html = "";
      $plugin_options = get_option( 'SUHV_WP_plugin_options' );
      $tablepress ='';
      if ((isset( $plugin_options['SUHV_css_tablepress']) == 1)) $tablepress = " tablepress";

      $api = new SwissUnihockey_Public();

      $details = $api->playerDetails($player_id, array(
        
      ));

      $data = $details->data; 
     
      $attributes = $data->regions[0]->rows[0]->cells;

      $player_name = $data->subtitle;
      $image_url = $attributes[0]->image->url;
      $club_name = $attributes[1]->text[0]; 
      $player_nr = $attributes[2]->text[0]; 
      $player_pos = $attributes[3]->text[0]; 
      $player_year =$attributes[4]->text[0]; 
      $player_size = $attributes[5]->text[0];
      $player_weight = $attributes[6]->text[0];


      $html .= "<div class=\"su-spieldaten\">";
      $html .= "<div class=\"su-container su-obj-spielerdetails\">";
      if ($player_id != NULL) {
        $html .= "<div class=\"su-header\"><span class=\"su-value-name\"><h2>".$player_nr." ".$player_name."</h2></span>"; 
      }
      else {
        $html .= "<div class=\"su-header\"><span class=\"su-value-name\"><h2>SUHV Player ID not set!</h2></span>"; 
      }
      $html .= "</div>";
      $html .= "<div class=\"su-row\">";
      $html .= "<div class=\"su-obj-portrait\">";
      $html .= "<span class=\"su-value-portrait\">";
      $html .= "<img src=\"".$image_url."\" alt=\"".$player_name." (Portrait)"."\"></span></div>";
      $html .= "<div class=\"su-obj-details\">";
      $html .= "<table class=\" su-table".$tablepress."\" cellpadding=\"0\" cellspacing=\"0\"><tbody>";
      $html .= "<tr><td class=\"su-strong\">Name:</td><td>".$player_name."</td></tr>";
      $html .= "<tr><td class=\"su-strong\">Nr:</td><td>".$player_nr."</td></tr>";     
      $html .= "<tr><td class=\"su-strong\">Position:</td><td>".$player_pos."</td></tr>";
      $html .= "<tr><td class=\"su-strong\">Jahrgang:</td><td>".$player_year."</td></tr>";
      $html .= "<tr><td class=\"su-strong\">Grösse:</td><td>". $player_size."</td></tr>";
      $html .= "<tr><td class=\"su-strong\">Gewicht:</td><td>". $player_weight."</td></tr>";
      $html .= "</tbody></table>";    
      $html .= "<div class=\"su-site-link\"><a href=\"https://www.swissunihockey.ch/de/player-detail?player_id=".$player_id."\">Spielerstatistik (Swissunihockey)</a></div>";
      $html .= "</div></div><!-- /su-row --></div><!-- /su-container --></div><!-- /su-spieldaten -->";

      if ($sponsor_name != NULL) {
       if ($sponsor_name[0] != '*') {
         $html .= "<h2 class=\"sponsor-header\">Sponsor</h2>";
         $html .= "<div class=\"sponsor-row\">";
         $html .= "<div class=\"sponsor-logo\"><a href=\"".$sponsor_link."\"><img src=\"".$sponsor_logo."\" alt=\"".$sponsor_name."\" /></a></div>";
         $html .= "<div class=\"sponsor-name\"><span><h3>".$sponsor_name."</h3>";
         $html .= "<h4><a href=\"".$sponsor_link."\">".$sponsor_link_title."</a></h4></span></div></div>";
       }
       else {
         $html .= "<h2 class=\"sponsor-header\">persönlicher Sponsor gesucht!</h2>";
         $html .= "<div class=\"sponsor-row\">";
         $html .= "<div class=\"sponsor-logo\"><a href=\"http://www.churunihockey.ch/sponsoring/kontakt-marketing/\"><img src=\"http://www.churunihockey.ch/wp-content/uploads/2013/08/ChurUnihockeyLogoSlide_460x368.png\" alt=\"www.churunihockey.ch\" /></a></div>";
         $html .= "<div class=\"sponsor-name\"><span><h3>- Hier könnte ihre Firma stehen -</h3>";
         $html .= "<h4><a href=\"http://www.churunihockey.ch/sponsoring/kontakt-marketing/\">"."www.churunihockey.ch"."</a></h4></span></div></div>";
       }
      }
      set_transient( $transient, $html, SwissUnihockey_Api_Public::cacheTime()*$trans_Factor);
    }
    else { $html = $value; }
    return $html;
   
      
  }


/* ---------------------------------------------------------------------------------------------------- */

  // Funktion: Log-Daten in WP-Debug schreiben
  public static function log_me($message) {
      if ( WP_DEBUG === true ) {
          if ( is_array($message) || is_object($message) ) {
              error_log( print_r($message, true) );
          } else {
                error_log( $message );
          }
      }
  }
/* ---------------------------------------------------------------------------------------------------- */
  public static function api_show_params($season, $club_ID, $team_ID, $mode) {

      echo "<br>Season: ".$season." - Club: ".$club_ID." - Team: ".$team_ID;
  }

}




