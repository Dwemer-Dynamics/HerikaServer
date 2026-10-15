<?php
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__).'/lib/follow_target.php';
$checks=0;
function check($ok,$why) { $GLOBALS['checks']++; if (!$ok) throw new RuntimeException($why); }
$cases=[
    ['Hero [RefID: 00012345]','FollowPlayer@'],
    ['hero','FollowPlayer@'],['PLAYER','FollowPlayer@'],['me','FollowPlayer@'],
    ['[RefID: 14]','FollowPlayer@'],['Hero [RefID: 0x12345]','FollowPlayer@'],
    ['Speaker',null],['speaker [RefID: 00012345]',null],['Other [RefID: 00012345]',null],
    ['[RefID: 00012345]',null],['',null],
    ['Guard [RefID: FF001234]','Follow@Guard [RefID: FF001234]'],['Other','Follow@Other'],
];
foreach ($cases as [$target,$expected]) check(chimResolveFollowTarget($target,'Hero','Speaker','00012345')===$expected,$target);
check(chimResolveFollowTarget('Somebody','Hero','Speaker')==='Follow@Somebody','missing ref does not block other names');
class FollowFixtureDb {
    function escape($value) { return $value; }
    function fetchOne($sql) { return ['refid'=>'00012345']; }
}
$GLOBALS['db']=new FollowFixtureDb();$GLOBALS['PLAYER_NAME']='Hero';
$source=file_get_contents($argv[1]??dirname(__DIR__).'/lib/data_functions.php');
$start=strpos($source,'                        // Resolve player intent and reject self-follow');
if ($start===false) $start=strpos($source,'                        // Parameter part');
$end=strpos($source,'                        if ($actionParts2[0]=="Attack")',$start);
$block=substr($source,$start,$end-$start);
$run=function($command)use($block){
    $actions=['Speaker|command|'.$command];
    eval('foreach ($actions as $n=>$action) { $actionParts=explode("|",$action); $actionParts2=explode("@",$actionParts[2]); ' . $block . '}');
    return array_values($actions);
};
// Use the production explicit-target parser without bootstrapping the server.
$a=strpos($source,'function chimNormalizeExplicitActorRefTarget(');
$b=strpos($source,'// Find actor name with closest name',$a);
eval(substr($source,$a,$b-$a));
check($run('Follow@Hero [RefID: 00012345]')===['Speaker|command|FollowPlayer@'],'player conversion must run before reference passthrough');
check($run('Follow@Other [RefID: 00012345]')===[],'self reference must never reach game');
check($run('Follow@Guard [RefID: FF001234]')===['Speaker|command|Follow@Guard [RefID: FF001234]'],'other explicit actor unchanged');
check($run('MoveTo@Guard [RefID: FF001234]')===['Speaker|command|MoveTo@Guard [RefID: FF001234]'],'unrelated action unchanged');
// The policy is shared by all speakers and player names, including spawned actors.
foreach (['Companion A'=>'00012345', 'Companion B'=>'FF001234', 'Guard'=>'FE123456'] as $speaker=>$speakerRef) {
    foreach (['Hero', 'Custom Player Name'] as $player) {
        foreach ([$player, "$player [RefID: $speakerRef]", 'PLAYER', 'me', '[RefID: 14]', 'Wrong Label [RefID: 00000014]'] as $target) {
            check(chimResolveFollowTarget($target,$player,$speaker,$speakerRef)==='FollowPlayer@','player route for '.$speaker);
        }
        check(chimResolveFollowTarget("$speaker [RefID: FF009999]",$player,$speaker,$speakerRef)==="Follow@$speaker [RefID: FF009999]",'same-name distinct actor is not self');
        check(chimResolveFollowTarget("Wrong Label [RefID: $speakerRef]",$player,$speaker,$speakerRef)===null,'self ID with wrong label is rejected');
    }
}

// Exercise the later name-resolution branch as well: fuzzy matching must not
// reintroduce self-follow after the early explicit-reference guard.
function FindClosestActorName($name) { return $GLOBALS['resolvedFollowFixture']; }
$a=strpos($source,'}  else if ($actionParts2[0]=="Follow") {');
$b=strpos($source,'} else if ($actionParts2[0]=="TravelTo") {',$a);
check($a!==false && $b!==false,'follow fallback block located');
$fallback=substr($source,strpos($source,'{',$a)+1,$b-strpos($source,'{',$a)-1);
foreach (['Hero'=>'FollowPlayer@','Speaker'=>null,'Guard'=>'Follow@Guard'] as $resolved=>$expected) {
    $GLOBALS['resolvedFollowFixture']=$resolved;
    $actions=['Speaker|command|Follow@Approximate Name'];
    eval('foreach ($actions as $n=>$action) { $actionParts=explode("|",$action); $actionParts2=explode("@",$actionParts[2]); ' . $fallback . '}');
    check(array_values($actions)===($expected===null?[]:['Speaker|command|'.$expected]),'resolved target '.$resolved);
}
echo "follow_target_probe: {$checks} checks passed\n";
