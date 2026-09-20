<?php

$code = <<<'ENDOFPHP'
<?php declare(strict_types=1); require_once __DIR__.'/../vendor/autoload.php';
$u=$_SERVER['REQUEST_URI']??'/'; $p=parse_url($u,PHP_URL_PATH);
$f=preg_replace('#^/documentation/#','',$p); $f=ltrim($f,'/');
if($f===''||stripos($f,'index')===0)$f='README.md';
$f=str_replace('..','',$f); $f=ltrim($f,'/');
$docRoot=__DIR__.'/../documentation'; $fp=realpath($docRoot.'/'.$f);
if($fp===false||!str_starts_with($fp,realpath($docRoot)?:'')){http_response_code(404);exit('404');}
$c=file_get_contents($fp); if($c===false){http_response_code(500);exit('500');}
$lang=preg_match('/\.id\.md$/',$f)?'id':'en';
function pm($t){$t=str_replace(["\r\n","\r"],"\n",$t);$t=htmlspecialchars($t,ENT_QUOTES|ENT_HTML5,'UTF-8');
$l=explode("\n",$t);$h='';$i=0;$ic=false;$cd='';$cl='';
while($i<count($l)){$ln=$l[$i];
if(str_starts_with($ln,'```')){if($ic){$h.='<pre><code class="language-'.htmlspecialchars($cl).'">'.htmlspecialchars($cd).'</code></pre>';$ic=false;$cd='';$cl='';$i++;continue;}
$ic=true;$cl=trim(substr($ln,3));$i++;continue;}
if($ic){$cd.=$ln."\n";$i++;continue;}
if(preg_match('/^-{3,}\s*$/',$ln)){$h.='<hr>';$i++;continue;}
if(preg_match('/^(#{1,6})\s+(.+)$/',$ln,$m)){$lvl=strlen($m[1]);$id=strtolower(trim(preg_replace('/[^a-z0-9]+/','-',strip_tags($m[2]))),'-');$h.='<h'.$lvl.' id="'.$id.'">'.im($m[2]).'</h'.$lvl.'>';$i++;continue;}
if(preg_match('/^>\s?(.*)$/',$ln,$m)){$bq=[$m[1]];$i++;while($i<count($l)&&preg_match('/^>\s?(.*)$/',$l[$i],$qm)){$bq[]=$qm[1];$i++;}$h.='<blockquote>'.im(implode("\n",$bq)).'</blockquote>';continue;}
if(preg_match('/^[\s]*[-*+]\s+(.+)$/',$ln,$m)){$h.='<ul>';while($i<count($l)&&preg_match('/^[\s]*[-*+]\s+(.+)$/',$l[$i],$lm)){$h.='<li>'.im($lm[1]).'</li>';$i++;}$h.='</ul>';continue;}
if(preg_match('/^\s*\d+\.\s+(.+)$/',$ln,$m)){$h.='<ol>';while($i<count($l)&&preg_match('/^\s*\d+\.\s+(.+)$/',$l[$i],$lm)){$h.='<li>'.im($lm[1]).'</li>';$i++;}$h.='</ol>';continue;}
if(str_contains($ln,'|')&&$i+1<count($l)&&preg_match('/^[\s|:]-+/',$l[$i+1]??'')){$h.=pt($l,$i);while($i<count($l)&&str_contains($l[$i],'|'))$i++;continue;}
if($ln===''){$i++;continue;}$h.='<p>'.im($ln).'</p>';$i++;}
$h=preg_replace('/<pre><code class="language-mermaid">(.*?)<\/code><\/pre>/s','<div class="mermaid">$1</div>',$h);return $h;}
function im($t){$t=preg_replace('/\*\*(.+?)\*\*/','<strong>$1</strong>',$t);$t=preg_replace('/\*(.+?)\*/','<em>$1</em>',$t);$t=preg_replace('/`(.+?)`/','<code>$1</code>',$t);
$t=preg_replace_callback('/\[(.+?)\]\((.+?)\)/',function($m){$u=$m[2];if(!preg_match('/^https?:\/\//',$u)&&str_ends_with($u,'.md'))$u='/documentation/'.ltrim($u,'/');$t=preg_match('/^https?:\/\//',$u)?' target="_blank" rel="noopener"':'';return '<a href="'.htmlspecialchars($u).'"'.$t.'>'.$m[1].'</a>';},$t);
$t=preg_replace('/!\[(.+?)\]\((.+?)\)/','<img src="$2" alt="$1" class="img-fluid">',$t);$t=preg_replace('/~~(.+?)~~/','<del>$1</del>',$t);return $t;}
function pt(array &$l,int &$s):string{$h=array_map('trim',explode('|',trim($l[$s])));$r='<table><thead><tr>';foreach($h as $c)$r.='<th>'.im($c).'</th>';$r.='</tr></thead><tbody>';$row=$s+2;while($row<count($l)&&str_contains($l[$row],'|')){$cells=explode('|',trim($l[$row]));$r.='<tr>';foreach($cells as $i=>$c)$r.='<td>'.im(trim($c)).'</td>';$r.='</tr>';$row++;}$r.='</tbody></table>';$s=$row-1;return $r;}
$html=pm($c); $title='FPDP Documentation';
if(preg_match('/<h1>(.*?)<\/h1>/',$html,$m))$title=strip_tags($m[1]).' · FPDP';
$files=[];$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docRoot,RecursiveDirectoryIterator::SKIP_DOTS));
foreach($it as$fn){if(in_array($fn->getExtension(),['md','yaml','yml'])){$r=str_replace($docRoot.'/','',$fn->getPathname());$files[]=['path'=>$r,'name'=>basename($r),'dir'=>dirname($r)];}}
sort($files);
ENDOFPHP;

file_put_contents(__DIR__ . '/public/documentation.php', $code);
echo "Part 1 written\n";