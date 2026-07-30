<?php
/**
 * L6 worker — jeden realny INSERT do wp_loadtest_aifaq_generations przez
 * mysqli bezpośrednio (osobny proces OS, prawdziwe równoległe zapisy).
 *
 * @package AI_FAQ_Generator
 */
$id = (int) ( $argv[1] ?? 0 );

$mysqli = mysqli_connect( '127.0.0.1', 'root', 'root', 'local', 10011 );
$topic  = 'temat-worker-' . $id;
$stmt   = $mysqli->prepare( 'INSERT INTO wp_loadtest_aifaq_generations (created_at,topic,extra_desc,num_questions,language,user_id,pairs_json) VALUES (NOW(),?,?,10,?,?,?)' );
$desc   = 'opis';
$lang   = 'pl';
$uid    = $id;
$pairs  = json_encode( array( array( 'q' => 'Q' . $id, 'a' => 'A' . $id ) ) );
$stmt->bind_param( 'sssis', $topic, $desc, $lang, $uid, $pairs );
$stmt->execute();
echo $mysqli->insert_id . "\n";
