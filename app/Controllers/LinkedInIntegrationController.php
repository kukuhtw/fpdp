<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Exceptions\NotFoundException;use App\Core\Exceptions\UnauthorizedException;use App\Core\Http\JsonEnvelope;use App\Core\Http\Request;use App\Core\Http\Response;use App\Repositories\ExternalAccountRepository;use App\Services\Auth\AuthService;use App\Services\External\LinkedInIntegrationService;use App\Services\Visitor\OAuthStateSigner;
final class LinkedInIntegrationController
{
    public function __construct(private readonly AuthService $auth,private readonly LinkedInIntegrationService $linkedin,private readonly ExternalAccountRepository $accounts,private readonly OAuthStateSigner $stateSigner){}
    public function status(Request $request):Response{$ctx=$this->auth->authenticate($request->bearerToken());return JsonEnvelope::success(['accounts'=>$this->accounts->listLinkedInByUser((int)$ctx['user']['id'])]);}
    public function authorize(Request $request):Response{$ctx=$this->auth->authenticate($request->bearerToken());$redirect=$this->callbackUrl($request);$state=$this->stateSigner->sign((string)$ctx['user']['id'],$redirect);return JsonEnvelope::success(['authorization_url'=>$this->linkedin->authorizationUrl($state,$redirect)]);}
    public function callback(Request $request):Response{if(!empty($request->query['error']))throw new UnauthorizedException((string)($request->query['error_description']??'LinkedIn authorization was denied.'));$code=(string)($request->query['code']??'');$state=(string)($request->query['state']??'');if($code===''||$state==='')throw new UnauthorizedException('Missing LinkedIn OAuth code or state.');$verified=$this->stateSigner->verify($state);$userId=(int)$verified['handle'];if($userId<1||$verified['redirect_uri']!==$this->callbackUrl($request))throw new UnauthorizedException('Invalid LinkedIn OAuth state.');$orgs=$this->linkedin->connectOrganizations($userId,$code,$verified['redirect_uri']);return Response::redirect('/dashboard/integrations?linkedin=connected&organizations='.count($orgs));}
    public function disconnect(Request $request,array $params):Response{$ctx=$this->auth->authenticate($request->bearerToken());if(!$this->accounts->deleteLinkedInOrganization((int)$ctx['user']['id'],(int)($params['accountId']??0)))throw new NotFoundException('LinkedIn organization connection not found.');return Response::noContent();}
    private function callbackUrl(Request $request):string{$scheme=$request->header('x-forwarded-proto')??'http';$host=$request->header('host')??'localhost';return$scheme.'://'.$host.'/api/v1/integrations/linkedin/callback';}
}
