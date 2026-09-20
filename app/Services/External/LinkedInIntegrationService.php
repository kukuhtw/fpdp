<?php
declare(strict_types=1);
namespace App\Services\External;
use App\Core\Config;use App\Core\Http\HttpClient;use App\Repositories\ExternalAccountRepository;use App\Repositories\ExternalFeedSourceRepository;use RuntimeException;
final class LinkedInIntegrationService
{
    public function __construct(private readonly ExternalAccountRepository $accounts,private readonly ExternalFeedSourceRepository $sources,private readonly HttpClient $http=new HttpClient()){}
    public function authorizationUrl(string $state,string $redirectUri):string{$id=(string)Config::get('LINKEDIN_CLIENT_ID','');if($id==='')throw new RuntimeException('LINKEDIN_CLIENT_ID is not configured.');return'https://www.linkedin.com/oauth/v2/authorization?'.http_build_query(['response_type'=>'code','client_id'=>$id,'redirect_uri'=>$redirectUri,'state'=>$state,'scope'=>'r_organization_admin r_organization_social']);}
    public function connectOrganizations(int $userId,string $code,string $redirectUri):array
    {
        $id=(string)Config::get('LINKEDIN_CLIENT_ID','');$secret=(string)Config::get('LINKEDIN_CLIENT_SECRET','');if($id===''||$secret==='')throw new RuntimeException('LinkedIn OAuth is not configured.');$tokenResponse=$this->requestJson('POST','https://www.linkedin.com/oauth/v2/accessToken',['Content-Type'=>'application/x-www-form-urlencoded','Accept'=>'application/json'],http_build_query(['grant_type'=>'authorization_code','code'=>$code,'client_id'=>$id,'client_secret'=>$secret,'redirect_uri'=>$redirectUri]));$token=(string)($tokenResponse['access_token']??'');if($token==='')throw new RuntimeException('LinkedIn did not return an access token.');$expires=isset($tokenResponse['expires_in'])?(int)$tokenResponse['expires_in']:null;$headers=$this->headers($token);
        $acls=$this->requestJson('GET','https://api.linkedin.com/rest/organizationAcls?'.http_build_query(['q'=>'roleAssignee','state'=>'APPROVED','count'=>100]),$headers);$ids=[];foreach((array)($acls['elements']??[])as$acl){$urn=(string)($acl['organization']??'');if(preg_match('/urn:li:organization:(\d+)/',$urn,$m)===1)$ids[$m[1]]=true;}$connected=[];
        foreach(array_keys($ids)as$orgId){$org=$this->requestJson('GET','https://api.linkedin.com/rest/organizations/'.rawurlencode($orgId),$headers);$name=(string)($org['localizedName']??('LinkedIn Organization '.$orgId));$vanity=(string)($org['vanityName']??'');$url=$vanity!==''?'https://www.linkedin.com/company/'.$vanity:'https://www.linkedin.com/company/'.$orgId;$accountId=$this->accounts->upsertLinkedInOrganization($userId,['id'=>$orgId,'name'=>$name,'url'=>$url],$token,$expires);$this->sources->ensureForExternalAccount($userId,'LINKEDIN','linkedin_organization',$url,$accountId);$connected[]=['id'=>$accountId,'organization_id'=>$orgId,'name'=>$name,'url'=>$url];}
        return$connected;
    }
    private function headers(string $token):array{return['Authorization'=>'Bearer '.$token,'Accept'=>'application/json','LinkedIn-Version'=>(string)Config::get('LINKEDIN_VERSION','202609'),'X-RestLi-Protocol-Version'=>'2.0.0'];}
    private function requestJson(string $method,string $url,array $headers,?string $body=null):array{$response=$this->http->request($method,$url,$headers,$body);$json=json_decode($response['body'],true);if($response['status']<200||$response['status']>=300||!is_array($json)||isset($json['error']))throw new RuntimeException((string)($json['message']??$json['error_description']??$json['error']??('LinkedIn HTTP '.$response['status'])));return$json;}
}
