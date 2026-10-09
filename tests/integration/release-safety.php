<?php
error_reporting(E_ALL & ~E_DEPRECATED);
if ( 'enabled' === getenv( 'CF_TRASH_MODE' ) ) { define( 'MEDIA_TRASH', true ); }
if ( 'zero-days' === getenv( 'CF_TRASH_MODE' ) ) { define( 'MEDIA_TRASH', true ); define( 'EMPTY_TRASH_DAYS', 0 ); }
require getenv( 'CF_WORDPRESS_ROOT' ) . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Run this fixture only in a disposable local WordPress installation.' ); }
require_once getenv('CF_MANAGER_ROOT') . '/cf-media-manager.php';
require_once getenv('CF_PLV_ROOT') . '/cf-post-list-view.php';
$checks=[];
function check_result($name,$ok,$detail=null){$GLOBALS['checks'][]=['name'=>$name,'pass'=>(bool)$ok,'detail'=>$detail];}
function fixture_user($login,$role){$u=get_user_by('login',$login);return $u?$u->ID:wp_insert_user(['user_login'=>$login,'user_pass'=>'fixture-only','role'=>$role]);}
$admin=fixture_user('review-admin','administrator');$author=fixture_user('fixture-author','author');$other=fixture_user('fixture-other','author');$contributor=fixture_user('fixture-contributor','contributor');$editor=fixture_user('fixture-editor','editor');
$prefix = 'Release privacy probe ' . uniqid();
wp_set_current_user($admin);
$private=wp_insert_post(['post_type'=>'post','post_status'=>'private','post_author'=>$other,'post_title'=>$prefix . ' private','post_excerpt'=>'Private fixture excerpt']);
$draft=wp_insert_post(['post_type'=>'post','post_status'=>'draft','post_author'=>$other,'post_title'=>$prefix . ' draft']);
$own=wp_insert_post(['post_type'=>'post','post_status'=>'draft','post_author'=>$contributor,'post_title'=>$prefix . ' own']);
register_post_type('fixture_secret',['public'=>true,'show_ui'=>true,'capability_type'=>['fixture_secret','fixture_secrets'],'map_meta_cap'=>true]);
$secret=wp_insert_post(['post_type'=>'fixture_secret','post_status'=>'private','post_author'=>$admin,'post_title'=>$prefix . ' CPT']);
$controller=new CFPostListView\RestController();
foreach(['contributor'=>$contributor,'author'=>$author,'editor'=>$editor] as $role=>$uid){
 wp_set_current_user($uid);
 $req=new WP_REST_Request('GET','/cf-plv/v1/posts');foreach(['page'=>1,'per_page'=>50,'search'=>$prefix,'post_type'=>'post','post_status'=>'','orderby'=>'id','order'=>'ASC','columns'=>['id','title','excerpt']] as $k=>$v)$req->set_param($k,$v);
 $resp=$controller->get_posts($req);$items=$resp instanceof WP_REST_Response?($resp->get_data()['items'] ?? []):[];$ids=array_column($items,'id');
 check_result($role.' REST private visibility',($role==='editor')===in_array($private,$ids),$ids);
 check_result($role.' REST draft visibility',($role==='editor')===in_array($draft,$ids),$ids);
 if($role==='contributor')check_result('own draft retained',in_array($own,$ids),$ids);
 $args=CFPostListView\CsvExporter::build_query_args($prefix,'post','');$q=new WP_Query($args);$csv_ids=wp_list_pluck($q->posts,'ID');
 check_result($role.' CSV private visibility',($role==='editor')===in_array($private,$csv_ids),$csv_ids);
 check_result($role.' CSV draft visibility',($role==='editor')===in_array($draft,$csv_ids),$csv_ids);
 if($role==='contributor'){$req->set_param('post_type','fixture_secret');$resp=$controller->get_posts($req);$items=$resp instanceof WP_REST_Response?($resp->get_data()['items'] ?? []):[];check_result('restricted CPT denied',!in_array($secret,array_column($items,'id')));}
}
wp_set_current_user($admin);
$attachment=wp_insert_attachment(['post_title'=>'Disposable trash fixture','post_status'=>'inherit','post_mime_type'=>'image/png']);
$report=new CFMediaManager\Audit\Reports\UnusedAttachments(new CFMediaManager\Audit\IgnoredStore(),fn()=>[]);
$result=$report->bulk_action('trash',[$attachment]);
$post=get_post($attachment);$trash_enabled=MEDIA_TRASH && EMPTY_TRASH_DAYS>0;
check_result('trash behavior respects core configuration',$trash_enabled ? ($post && $post->post_status==='trash') : ($post && $post->post_status==='inherit' && !empty($result->errors)),['MEDIA_TRASH'=>MEDIA_TRASH,'EMPTY_TRASH_DAYS'=>EMPTY_TRASH_DAYS,'post_status'=>$post?$post->post_status:null,'errors'=>$result->errors]);
if($trash_enabled){$again=$report->bulk_action('trash',[$attachment]);check_result('repeat trash never deletes record',get_post($attachment)!==null);}
foreach([$private,$draft,$own,$secret,$attachment] as $id)wp_delete_post($id,true);
echo wp_json_encode(['WordPress'=>get_bloginfo('version'),'checks'=>$checks,'failed'=>count(array_filter($checks,fn($c)=>!$c['pass']))],JSON_PRETTY_PRINT),"\n";
exit(count(array_filter($checks,fn($c)=>!$c['pass']))?1:0);
