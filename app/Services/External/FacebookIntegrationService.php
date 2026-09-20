<?php
declare(strict_types=1);
namespace App\Services\External;
use App\Core\Config;
use App\Core\Http\HttpClient;
use App\Repositories\ExternalAccountRepository;
use App\Repositories\ExternalFeedSourceRepository;
use RuntimeException;
final class FacebookIntegrationService
{
    public function __construct(private readonly ExternalAccountRepository $accounts,private readonly ExternalFeedSourceRepository $sources,private readonly HttpClient $http=new HttpClient()){}
    public function authorizationUrl(string $state,string $redirectUri):string
    {
        $appId=(string)Config::get('FACEBOOK_APP_ID','');if($appId==='')throw new RuntimeException('FACEBOOK_APP_ID is not configured.');$v=(string)Config::get('FACEBOOK_GRAPH_VERSION','v26.0');
        return 'https://www.facebook.com/'.rawurlencode($v).'/dialog/oauth?'.http_build_query(['client_id'=>$appId,'redirect_uri'=>$redirectUri,'state'=>$state,'response_type'=>'code','scope'=>'pages_show_list,pages_read_engagement']);
    }
    public function connectPages(int $userId,string $code,string $redirectUri):array
    {
        $appId=(string)Config::get('FACEBOOK_APP_ID','');$secret=(string)Config::get('FACEBOOK_APP_SECRET','');if($appId===''||$secret==='')throw new RuntimeException('Facebook OAuth is not configured.');$v=(string)Config::get('FACEBOOK_GRAPH_VERSION','v26.0');$base='https://graph.facebook.com/'.rawurlencode($v);
        $short=$this->json($base.'/oauth/access_token?'.http_build_query(['client_id'=>$appId,'client_secret'=>$secret,'redirect_uri'=>$redirectUri,'code'=>$code]));$userToken=(string)($short['access_token']??'');if($userToken==='')throw new RuntimeException('Meta did not return a user access token.');
        $long=$this->json($base.'/oauth/access_token?'.http_build_query(['grant_type'=>'fb_exchange_token','client_id'=>$appId,'client_secret'=>$secret,'fb_exchange_token'=>$userToken]));$userToken=(string)($long['access_token']??$userToken);
        $pages=$this->json($base.'/me/accounts?'.http_build_query(['fields'=>'id,name,username,link,access_token,tasks','limit'=>100]),$userToken);$connected=[];
        foreach((array)($pages['data']??[])as$page){if(!is_array($page)||empty($page['id'])||empty($page['access_token'])||empty($page['name']))continue;$accountId=$this->accounts->upsertFacebookPage($userId,$page);$url=(string)($page['link']??('https://www.facebook.com/'.$page['id']));$this->sources->ensureForExternalAccount($userId,'FACEBOOK','facebook_page',$url,$accountId);$connected[]=['id'=>$accountId,'page_id'=>$page['id'],'name'=>$page['name'],'url'=>$url];}
        return $connected;
    }
    private function json(string $url,?string $token=null):array
    {
        $headers=['Accept'=>'application/json'];if($token!==null)$headers['Authorization']='Bearer '.$token;$response=$this->http->get($url,$headers);$body=json_decode($response['body'],true);if($response['status']<200||$response['status']>=300||!is_array($body)||isset($body['error']))throw new RuntimeException((string)($body['error']['message']??('Meta Graph API HTTP '.$response['status'])));return$body;
    }
}
