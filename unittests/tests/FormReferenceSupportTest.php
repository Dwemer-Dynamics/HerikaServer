<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'game_plugins.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'quest_reference_data.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'npc_master.class.php';

final class FormReferenceSupportTest extends TestCase
{
    public function testNpcReferencesSurviveNormalAndLightLoadOrderChanges(): void
    {
        $old = [
            ['plugin_name' => 'First.esp', 'formid_prefix' => '05'],
            ['plugin_name' => 'Second.esp', 'formid_prefix' => '07'],
            ['plugin_name' => 'Light.esp', 'formid_prefix' => 'FE012', 'is_light' => true],
        ];
        $new = $old;
        $new[0]['formid_prefix'] = '07';
        $new[1]['formid_prefix'] = '05';
        $new[2]['formid_prefix'] = 'FE034';
        $rows = [];
        foreach (['05001234', '07001234', 'FE012ABC', 'FF001234'] as $index => $refid) {
            $rows[] = ['id' => $index + 1, 'npc_name' => 'Guard', 'refid' => $refid, 'metadata' => '{}'];
        }
        $updates = chimPlanNpcReferenceRemap($rows, $old, $new);
        $this->assertSame(['07001234', '05001234', 'FE034ABC'], array_column($updates, 'refid'));
        $this->assertSame(['First.esp|00001234', 'Second.esp|00001234', 'Light.esp|00000ABC'], array_column($updates, 'source'));
        $this->assertSame(md5('ref:first.esp|00001234'), $updates[0]['md5']);
        $this->assertSame('First.esp|00001234', chimParseNpcReferenceSource('First.esp/00001234')['stable_key']);
        $this->assertNull(chimParseNpcReferenceSource('../First.esp/1234'));
        $this->assertNull(chimParseNpcReferenceSource('First.esp/FF001234'));
        $this->assertSame('VR.esp|00012ABC', chimConvertRuntimeFormIdToStableReference('FE012ABC',
            chimIndexLoadedGamePluginsByPrefix([['plugin_name' => 'VR.esp', 'formid_prefix' => 'FE']])));
    }

    public function testStableProfileSelectorIgnoresNameAndRuntimePrefix(): void
    {
        $actor = ['npc_name' => 'Guard', 'refid' => '05001234',
            'metadata' => ['refid_source' => 'First.esp|00001234']];
        $renamed = array_replace($actor, ['npc_name' => 'Captain', 'refid' => '09001234']);
        $this->assertSame(NpcMaster::identityMd5($actor), NpcMaster::identityMd5($renamed));
        $this->assertSame(md5('ref:first.esp|00001234'), NpcMaster::identityMd5($actor));
        $this->assertSame(md5('runtime:FF001234'), NpcMaster::identityMd5([
            'npc_name' => 'Bandit', 'refid' => 'FF001234', 'metadata' => '{}']));
    }

    public function testRemovedPluginProfilesRemainUnavailableUntilTheirPluginReturns(): void
    {
        $row = ['id' => 1, 'npc_name' => 'Guard', 'refid' => '05001234',
            'metadata' => '{"refid_source":"First.esp|00001234","mods":["First.esp"]}'];
        $other = [['plugin_name' => 'Unrelated.esp', 'formid_prefix' => '05']];
        $updates = chimPlanNpcReferenceRemap([$row], [], $other);
        $this->assertNull($updates[0]['refid']);
        $row['refid'] = null;
        $row['md5'] = $updates[0]['md5'];
        $this->assertSame($row['md5'], NpcMaster::identityMd5($row));
        $this->assertSame([], chimPlanNpcReferenceRemap([$row], [], $other));
        $restored = chimPlanNpcReferenceRemap([$row], $other, [['plugin_name' => 'First.esp', 'formid_prefix' => '09']]);
        $this->assertSame('09001234', $restored[0]['refid']);
    }

    public function testNpcReferenceRemapRejectsAmbiguousDestinations(): void
    {
        $rows = [
            ['id' => 1, 'npc_name' => 'Guard', 'refid' => '05001234', 'metadata' => '{}'],
            ['id' => 2, 'npc_name' => 'Guard', 'refid' => '07001234', 'metadata' => '{}'],
        ];
        $this->expectException(RuntimeException::class);
        chimPlanNpcReferenceRemap($rows,
            [['plugin_name' => 'First.esp', 'formid_prefix' => '05']],
            [['plugin_name' => 'First.esp', 'formid_prefix' => '07']]);
    }

    protected function setUp(): void
    {
        $GLOBALS['db'] = new class {
            public function escape($value): string
            {
                return str_replace("'", "''", (string) $value);
            }

            public function fetchOne(string $query): ?array
            {
                if (
                    stripos($query, "where lower(plugin_name) = lower('mymod.esp')") !== false
                    || stripos($query, "where formid_prefix = '02'") !== false
                ) {
                    return [
                        'plugin_name' => 'MyMod.esp',
                        'is_light' => false,
                        'compile_index' => 2,
                        'small_file_compile_index' => 0,
                        'partial_index' => 0,
                        'formid_prefix' => '02',
                        'updated_at' => '2026-04-27 00:00:00',
                    ];
                }

                if (
                    stripos($query, "where lower(plugin_name) = lower('somelight.esl')") !== false
                    || stripos($query, "where formid_prefix = 'FE123'") !== false
                ) {
                    return [
                        'plugin_name' => 'SomeLight.esl',
                        'is_light' => true,
                        'compile_index' => 254,
                        'small_file_compile_index' => 0x123,
                        'partial_index' => 0x123,
                        'formid_prefix' => 'FE123',
                        'updated_at' => '2026-04-27 00:00:00',
                    ];
                }

                if (
                    stripos($query, "where lower(plugin_name) = lower('skyrim.esm')") !== false
                    || stripos($query, "where formid_prefix = '00'") !== false
                ) {
                    return [
                        'plugin_name' => 'Skyrim.esm',
                        'is_light' => false,
                        'compile_index' => 0,
                        'small_file_compile_index' => 0,
                        'partial_index' => 0,
                        'formid_prefix' => '00',
                        'updated_at' => '2026-04-27 00:00:00',
                    ];
                }

                if (
                    stripos($query, "where lower(plugin_name) = lower('aiagent.esp')") !== false
                    || stripos($query, "where formid_prefix = '05'") !== false
                ) {
                    return [
                        'plugin_name' => 'AIAgent.esp',
                        'is_light' => false,
                        'compile_index' => 5,
                        'small_file_compile_index' => 0,
                        'partial_index' => 0,
                        'formid_prefix' => '05',
                        'updated_at' => '2026-04-27 00:00:00',
                    ];
                }

                return null;
            }
        };
    }

    public function testQuestReferenceHelpersSupportStableReferences(): void
    {
        $this->assertSame(
            'MyMod.esp|000086EE',
            quest_reference_canonicalize_formid_for_text_storage('MyMod.esp|86ee')
        );
        $this->assertSame(
            hexdec('020086EE'),
            quest_reference_normalize_formid('MyMod.esp|000086EE')
        );

        $this->assertSame(
            'SomeLight.esl|00000822',
            quest_reference_canonicalize_formid_for_text_storage('SomeLight.esl|822')
        );
        $this->assertSame(
            hexdec('FE123822'),
            quest_reference_normalize_formid('SomeLight.esl|00000822')
        );
    }

    public function testRuntimeFormIdsAreCanonicalizedToStableStorageReferences(): void
    {
        $this->assertSame(
            'MyMod.esp|000086EE',
            quest_reference_canonicalize_formid_for_text_storage('0x020086ee')
        );
        $this->assertSame(
            'SomeLight.esl|00000822',
            quest_reference_canonicalize_formid_for_text_storage('FE123822')
        );
        $this->assertSame(
            'Skyrim.esm|0001397E',
            quest_reference_canonicalize_formid_for_text_storage('0x0001397e')
        );
    }

    public function testUnresolvedRuntimeIdsArePreservedAndDynamicIdsAreRejected(): void
    {
        $this->assertSame(
            '0x03001234',
            quest_reference_canonicalize_formid_for_text_storage('0x03001234')
        );
        $this->assertNull(
            quest_reference_canonicalize_formid_for_text_storage('0xFF001234')
        );

        $unresolved = quest_reference_classify_formid_for_text_storage('0x03001234');
        $this->assertSame('unresolved', $unresolved['status']);

        $dynamic = quest_reference_classify_formid_for_text_storage('0xFF001234');
        $this->assertSame('dynamic', $dynamic['status']);
    }

    public function testLegacyQuestReferenceRepairUsesManifestPrefixesWithoutDroppingUnknownValues(): void
    {
        $plugins = [
            [
                'plugin_name' => 'MyMod.esp',
                'is_light' => false,
                'compile_index' => 2,
                'formid_prefix' => '02',
            ],
            [
                'plugin_name' => 'SomeLight.esl',
                'is_light' => true,
                'compile_index' => 254,
                'small_file_compile_index' => 0x123,
                'formid_prefix' => 'FE123',
            ],
        ];
        $pluginsByPrefix = chimIndexLoadedGamePluginsByPrefix($plugins);
        $pluginsByName = chimIndexLoadedGamePluginsByName($plugins);

        $repair = quest_reference_repair_formid_values(
            'item_types',
            'weapon',
            [
                '0x020086ee',
                'MyMod.esp|86EE',
                '0xFE123822',
                '0x03001234',
                '0xFF001234',
                'not-a-formid',
            ],
            $pluginsByPrefix,
            $pluginsByName
        );

        $this->assertSame([
            'MyMod.esp|000086EE',
            'SomeLight.esl|00000822',
            '0x03001234',
            '0xFF001234',
            'not-a-formid',
        ], $repair['values']);
        $this->assertTrue($repair['changed']);
        $this->assertSame(2, $repair['converted']);
        $this->assertSame(1, $repair['unresolved']);
        $this->assertSame(1, $repair['dynamic']);
        $this->assertSame(1, $repair['invalid']);
    }

    public function testLegacyAIAgentLocalIdsDoNotGetMisidentifiedAsSkyrimForms(): void
    {
        $plugins = [
            [
                'plugin_name' => 'Skyrim.esm',
                'is_light' => false,
                'compile_index' => 0,
                'formid_prefix' => '00',
            ],
            [
                'plugin_name' => 'AIAgent.esp',
                'is_light' => false,
                'compile_index' => 5,
                'formid_prefix' => '05',
            ],
        ];
        $pluginsByPrefix = chimIndexLoadedGamePluginsByPrefix($plugins);
        $pluginsByName = chimIndexLoadedGamePluginsByName($plugins);

        $npcTemplate = quest_reference_classify_dataset_formid_for_text_storage(
            'npc_own_templates',
            'female_breton_noble',
            '0x00025844',
            $pluginsByPrefix,
            $pluginsByName
        );
        $this->assertSame('AIAgent.esp|00025844', $npcTemplate['value']);
        $this->assertSame(
            hexdec('05025844'),
            quest_reference_normalize_formid($npcTemplate['value'])
        );

        $questItem = quest_reference_classify_dataset_formid_for_text_storage(
            'item_types',
            'potion',
            '0x0002481F',
            $pluginsByPrefix,
            $pluginsByName
        );
        $this->assertSame('AIAgent.esp|0002481F', $questItem['value']);

        $legacyBook = quest_reference_classify_dataset_formid_for_text_storage(
            'item_types',
            'book',
            '0x000CE70B',
            $pluginsByPrefix,
            $pluginsByName
        );
        $this->assertSame('Skyrim.esm|000CE70B', $legacyBook['value']);

        $customVanillaTemplate = quest_reference_classify_dataset_formid_for_text_storage(
            'npc_own_templates',
            'custom_template',
            '0x00025844',
            $pluginsByPrefix,
            $pluginsByName
        );
        $this->assertSame('Skyrim.esm|00025844', $customVanillaTemplate['value']);

        $vanillaTemplate = quest_reference_classify_dataset_formid_for_text_storage(
            'npc_templates',
            'male_redguard',
            '0x00013BAA',
            $pluginsByPrefix,
            $pluginsByName
        );
        $this->assertSame('Skyrim.esm|00013BAA', $vanillaTemplate['value']);
    }

    public function testPluginManifestRepairUpdatesLegacyQuestReferenceRows(): void
    {
        $db = new class {
            public array $queries = [];

            public function escape($value): string
            {
                return str_replace("'", "''", (string) $value);
            }

            public function fetchOne(string $query): ?array
            {
                if (stripos($query, 'information_schema.tables') !== false) {
                    return ['n' => 1];
                }
                if (stripos($query, 'information_schema.columns') !== false) {
                    return ['n' => 1];
                }

                return null;
            }

            public function fetchAll(string $query): array
            {
                if (stripos($query, 'from public.quest_item_types') !== false) {
                    return [[
                        'key_name' => 'weapon',
                        'formids_json' => '["0x020086ee","0x03001234"]',
                    ]];
                }

                return [];
            }

            public function execQuery(string $query): bool
            {
                $this->queries[] = $query;
                return true;
            }
        };
        $GLOBALS['db'] = $db;

        $repair = quest_reference_repair_runtime_formids_to_stable([
            [
                'plugin_name' => 'MyMod.esp',
                'is_light' => false,
                'compile_index' => 2,
                'formid_prefix' => '02',
            ],
        ]);

        $this->assertNull($repair['error']);
        $this->assertSame(1, $repair['rows_scanned']);
        $this->assertSame(1, $repair['rows_updated']);
        $this->assertSame(1, $repair['converted']);
        $this->assertSame(1, $repair['unresolved']);

        $updateQueries = array_values(array_filter(
            $db->queries,
            static fn (string $query): bool => stripos($query, 'UPDATE public.quest_item_types') !== false
        ));
        $this->assertCount(1, $updateQueries);
        $this->assertStringContainsString('MyMod.esp|000086EE', $updateQueries[0]);
        $this->assertStringContainsString('0x03001234', $updateQueries[0]);
    }

    public function testSharedCharacterOverlayPreservesPhysicalActorIdentity(): void
    {
        $actor = ['id' => 3, 'npc_name' => 'Astrid', 'refid' => '05012345', 'md5' => 'physical',
            'profile_owner_npc_id' => 2, 'personality' => 'other', 'appearance' => 'burnt',
            'metadata' => '{"refid_source":"Alternate.esp|00012345","health":20}',
            'extended_data' => '{"factions":["physical"],"middle_term_memory":{"1":"other"},"custom":true}'];
        $GLOBALS['db'] = new class {
            public function fetchOne($query) {
                return ['id' => 2, 'npc_name' => 'Astrid', 'refid' => '00013475', 'personality' => 'kept',
                    'extended_data' => '{"middle_term_memory":{"2":"shared"},"factions":["owner"]}'];
            }
        };
        $effective = chimNpcEffectiveProfile($actor);
        $this->assertSame('kept', $effective['personality']);
        foreach (['id', 'refid', 'md5', 'appearance', 'metadata'] as $field) {
            $this->assertSame($actor[$field], $effective[$field]);
        }
        $this->assertSame(['physical'], chimNpcProfileJson($effective['extended_data'])['factions']);
        $this->assertSame([2 => 'shared'], chimNpcProfileJson($effective['extended_data'])['middle_term_memory']);
        $this->assertTrue(chimNpcProfileJson($effective['extended_data'])['custom']);
        $this->assertSame([], chimNpcEffectiveProfile([]));
    }

    public function testEventParticipantsKeepExactIdentityWithoutInferringFromNames(): void
    {
        $astrid = 'ref:skyrim.esm|0001BDE8';
        $this->assertSame($astrid, chimActorKeyFromReference('Skyrim.esm|1BDE8'));
        $this->assertSame(md5($astrid), NpcMaster::identityMd5(['metadata' => ['refid_source' => 'Skyrim.esm|0001BDE8']]));
        $this->assertSame($astrid, chimNpcRowActorKey(['metadata' => '{"refid_source":"Skyrim.esm|0001BDE8"}']));
        $this->assertNull(chimNpcRowActorKey(['npc_name' => 'Astrid', 'refid' => '0001BDE8', 'metadata' => '{}']));
        foreach (['player', 'narrator', 'dyn:0f8fad5b-d9cb-469f-a165-70867728950e', "ref:bob's mod.esp|00000ABC"] as $key) {
            $this->assertTrue(chimIsActorKey($key), $key);
        }
        foreach (['Player', 'runtime:FF001234', 'FF001234', 'ref:Skyrim.esm|0001BDE8', 'ref:skyrim.esm|0001bde8',
            'ref:skyrim.esm|FF001234', 'ref: skyrim.esm|0001BDE8', 'dyn:00000000-0000-0000-0000-000000000000',
            'base:skyrim.esm|0001BDE8', 'ref:a\b.esp|00000001', 'Astrid', null, 7] as $key) {
            $this->assertFalse(chimIsActorKey($key), var_export($key, true));
        }

        $people = chimSerializeEventParticipants([
            ['name' => 'Astrid (busy)', 'id' => $astrid],
            ['name' => 'Guard', 'id' => 'ref:skyrim.esm|00012345'],
            ['name' => 'Guard', 'id' => 'ref:skyrim.esm|00054321'],
            ['name' => 'Astrid the Renamed', 'id' => $astrid],
            ['name' => 'Lydia'],
            ['name' => 'Prisoner', 'id' => 'player'],
        ]);
        $this->assertSame('[{"name":"Astrid (busy)","id":"ref:skyrim.esm|0001BDE8"},'
            . '{"name":"Guard","id":"ref:skyrim.esm|00012345"},{"name":"Guard","id":"ref:skyrim.esm|00054321"},'
            . '{"name":"Lydia"},{"name":"Prisoner","id":"player"}]', $people);
        $parsed = chimParseEventParticipants($people);
        $this->assertSame(2, $parsed['version']);
        $this->assertSame(['Astrid', 'busy'], [$parsed['participants'][0]['base_name'], $parsed['participants'][0]['status']]);
        $this->assertNull($parsed['participants'][3]['id']);
        $this->assertSame([$astrid, 'ref:skyrim.esm|00012345', 'ref:skyrim.esm|00054321', 'player'],
            chimEventParticipantKeys($people));

        // Legacy and malformed rows stay name-only; nothing is upgraded by matching names.
        $legacy = chimParseEventParticipants('|Astrid (in combat)|Guard|Guard|');
        $this->assertSame(1, $legacy['version']);
        $this->assertSame(['Astrid (in combat)', 'Guard'], array_column($legacy['participants'], 'name'));
        $this->assertSame([], chimEventParticipantKeys('|Astrid|'));
        $this->assertSame([], chimEventParticipantKeys('[{"name":"Astrid","id":"ref:skyrim.esm|0001BDE8"'));
        $this->assertSame([], chimEventParticipantKeys('[{"name":"A\u0000","id":"player"},{"name":"B","id":"narrator"}]'));
        // Stored history is read tolerantly: only whole valid entries keep keys, as in the SQL extractor.
        $stored = '["Astrid",{"id":"player"},{"name":"A|B","id":"narrator"},{"name":"Guard","id":"0001BDE8"},'
            . '{"name":"Sven","id":{"x":1}},[{"name":"N","id":"dyn:0f8fad5b-d9cb-469f-a165-70867728950e"}],'
            . '{"name":"Tab\t","id":"ref:skyrim.esm|00000007"},{"name":"The Narrator","id":"narrator"}]';
        $this->assertSame(['narrator'], chimEventParticipantKeys($stored));
        $this->assertSame(['Astrid', 'A|B', 'Guard', 'Sven'], array_column(chimParseEventParticipants($stored)['participants'], 'name'));
        $this->assertSame(['[Merchant] Bob'], array_column(chimParseEventParticipants('[Merchant] Bob')['participants'], 'name'));
        $this->assertSame([], chimEventParticipantKeys(null));

        // Client ingress: only an absent capability is legacy; anything else is complete or rejected.
        $this->assertNull(chimEventIdentityParticipants(['participants' => [['name' => 'Astrid', 'id' => $astrid]]]));
        $accepted = chimEventIdentityParticipants(['identity_version' => 2,
            'participants' => [['name' => 'Astrid', 'id' => $astrid], ['name' => 'Bandit'], (object)['name' => 'Ulfric|Jarl']]]);
        $this->assertSame([$astrid, null, null], array_column($accepted, 'id'));
        $this->assertSame('Ulfric|Jarl', $accepted[2]['name']);
        $this->assertSame([], chimEventIdentityParticipants(['identity_version' => 2, 'participants' => []]));
        $rejected = [
            'unsupported_version' => [['identity_version' => '2', 'participants' => []], ['identity_version' => null],
                ['identity_version' => 3, 'participants' => []]],
            'participants_invalid' => [['identity_version' => 2], ['identity_version' => 2, 'participants' => 'Astrid'],
                ['identity_version' => 2, 'participants' => ['a' => ['name' => 'Astrid']]]],
            'participant_invalid' => [['identity_version' => 2, 'participants' => [['name' => 'Lydia'], 'Astrid']],
                ['identity_version' => 2, 'participants' => [[]]], ['identity_version' => 2, 'participants' => [['id' => 'player']]],
                ['identity_version' => 2, 'participants' => [['name' => 'Astrid', 'formid' => '0001BDE8']]]],
            'participant_name_invalid' => [['identity_version' => 2, 'participants' => [['name' => ' ']]],
                ['identity_version' => 2, 'participants' => [['name' => "A\tB"]]], ['identity_version' => 2, 'participants' => [['name' => 7]]]],
            'participant_id_invalid' => [['identity_version' => 2, 'participants' => [['name' => 'Lydia'], ['name' => 'Bandit', 'id' => 'FF001234']]],
                ['identity_version' => 2, 'participants' => [['name' => 'Bandit', 'id' => null]]],
                ['identity_version' => 2, 'participants' => [['name' => 'Bandit', 'id' => '']]]],
        ];
        foreach ($rejected as $reason => $payloads) {
            foreach ($payloads as $payload) {
                try {
                    chimEventIdentityParticipants($payload);
                    $this->fail($reason . ' accepted: ' . json_encode($payload));
                } catch (ChimEventIdentityException $error) {
                    $this->assertSame($reason, $error->reason, json_encode($payload));
                }
            }
        }

        // Serialization enforces the same boundary; parsed participants round-trip with 'id' => null.
        $this->assertSame('[{"name":"Ulfric|Jarl"},{"name":"Astrid","id":"ref:skyrim.esm|0001BDE8"}]',
            chimSerializeEventParticipants([['name' => 'Ulfric|Jarl', 'id' => null], ['name' => 'Astrid', 'id' => $astrid]]));
        $this->assertSame($people, chimSerializeEventParticipants($parsed['participants']));
        foreach ([[['name' => 'Bandit', 'id' => 'FF001234']], [['name' => "A\x7F"]], ['Astrid'], [['name' => 'A', 'extra' => 1]]] as $bad) {
            try {
                chimSerializeEventParticipants($bad);
                $this->fail('Invalid participant serialized: ' . json_encode($bad));
            } catch (ChimEventIdentityException $expected) {
                $this->assertInstanceOf(InvalidArgumentException::class, $expected);
            }
        }

        require_once __DIR__ . '/../../lib/eventlog_helper.php';
        $db = new class { public function escape($value) { return str_replace("'", "''", (string)$value); } };
        $this->assertSame('FALSE', chimBuildEventLogActorKeysWhereClause($db, ['Astrid', 'FF001234']));
        $this->assertSame("(left(e.people, 1) = '[' AND public.chim_eventlog_actor_keys(e.people) && "
            . "ARRAY['ref:skyrim.esm|0001BDE8','ref:bob''s mod.esp|00000ABC']::text[])",
            chimBuildEventLogActorKeysWhereClause($db, [$astrid, $astrid, "ref:bob's mod.esp|00000ABC"], 'e.people'));
    }

    public function testContextScopesAndRegistrationKeysAreTypedNotNamed(): void
    {
        require_once __DIR__ . '/../../lib/eventlog_helper.php';
        $db = new class { public function escape($value) { return str_replace("'", "''", (string)$value); } };
        // Reserved keys come only from typed principals; names (even the player's or "The Narrator") never map.
        $GLOBALS['PLAYER_NAME'] = 'Prisoner';
        $this->assertSame(['player'], chimResolveContextActorKeys($db, CHIM_ACTOR_KEY_PLAYER));
        $this->assertSame(['narrator'], chimResolveContextActorKeys($db, CHIM_ACTOR_KEY_NARRATOR));
        $this->assertSame([], chimResolveContextActorKeys($db, 'Prisoner'));
        $this->assertSame([], chimResolveContextActorKeys($db, 'The Narrator'));
        $this->assertSame([], chimResolveContextActorKeys($db, null));
        $this->assertSame('FALSE', chimBuildNpcContextPeopleWhereClause($db, 'The Narrator'));

        // addnpc/addbgnpc field 45: ref: must equal the verified source; dyn: only without one.
        $dyn = 'dyn:0f8fad5b-d9cb-469f-a165-70867728950e';
        $this->assertNull(chimRegistrationActorKey('', 'Skyrim.esm|0001BDE8'));
        $this->assertSame('ref:skyrim.esm|0001BDE8', chimRegistrationActorKey('ref:skyrim.esm|0001BDE8', 'Skyrim.esm|0001BDE8'));
        $this->assertSame($dyn, chimRegistrationActorKey($dyn, null));
        // The ref: key's `|` survives the request split; only the registration payload is rejoined.
        $fields = array_fill(0, 46, '');
        [$fields[0], $fields[4], $fields[44], $fields[45]] = ['Astrid', '0001BDE8', 'Skyrim.esm/0001BDE8', 'ref:skyrim.esm|0001BDE8'];
        foreach (['addnpc', 'ADDBGNPC'] as $type) {
            $request = chimJoinRegistrationRequestFields(explode('|', "{$type}|1|2|" . implode('@', $fields)));
            $this->assertCount(4, $request);
            $this->assertSame($type, $request[0]);
            $this->assertSame('ref:skyrim.esm|0001BDE8', chimRegistrationActorKey(explode('@', $request[3])[45], 'Skyrim.esm|0001BDE8'));
        }
        $this->assertSame(['addnpc', '1', '2', 'Astrid@Base'], chimJoinRegistrationRequestFields(['addnpc', '1', '2', 'Astrid@Base']));
        $this->assertSame(['inputtext', '1', '2', 'a', 'b'], chimJoinRegistrationRequestFields(['inputtext', '1', '2', 'a', 'b']));
        foreach ([[$dyn, 'Skyrim.esm|0001BDE8'], ['ref:skyrim.esm|00012345', 'Skyrim.esm|0001BDE8'], ['player', null], ['Astrid', null]] as [$key, $source]) {
            try {
                chimRegistrationActorKey($key, $source);
                $this->fail("accepted {$key}");
            } catch (ChimEventIdentityException $e) {
                $this->assertSame('role_key_invalid:actor_key', $e->reason);
            }
        }

        // A dyn: row is selected by its key; a row that released a recycled slot keeps its recorded selector.
        $this->assertSame(md5($dyn), NpcMaster::identityMd5(['npc_name' => 'Nord', 'refid' => 'FF000801', 'metadata' => ['actor_key' => $dyn]]));
        $kept = md5('runtime:FF000801');
        $this->assertSame($kept, NpcMaster::identityMd5(['npc_name' => 'Nord', 'refid' => null, 'metadata' => ['detached_selector' => $kept]]));
        $this->assertSame(md5('Nord'), NpcMaster::identityMd5(['npc_name' => 'Nord', 'refid' => null, 'metadata' => ['detached_selector' => 'Nord']]));
    }

    public function testNpcMasterSupportsStableFactionDetection(): void
    {
        $npcData = [
            'extended_data' => json_encode([
                'factions' => [
                    [
                        'formid' => '020086EE',
                        'rank' => 0,
                        'plugin' => 'MyMod.esp',
                        'local_formid' => '000086EE',
                        'stable_key' => 'MyMod.esp|000086EE',
                    ],
                ],
            ]),
        ];

        $npcMaster = new NpcMaster();

        $this->assertTrue($npcMaster->isNpcInFaction($npcData, 'MyMod.esp|000086EE'));
        $this->assertTrue($npcMaster->isNpcInFaction($npcData, '020086EE'));
        $this->assertFalse($npcMaster->isNpcInFaction($npcData, 'MyMod.esp|00001234'));
    }
}
