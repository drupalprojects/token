<?php

namespace Drupal\Tests\token\Kernel;

use Drupal\Component\Utility\Unicode;
use Drupal\contact\Entity\ContactForm;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Render\Markup;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Tests\EntityReference\EntityReferenceTestTrait;
use Drupal\filter\Entity\FilterFormat;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\contact\Entity\Message;
use Drupal\Component\Utility\Html;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\taxonomy\Tests\TaxonomyTestTrait;

/**
 * Tests field tokens.
 *
 * @group token
 */
class FieldTest extends KernelTestBase {

  use TaxonomyTestTrait;
  use EntityReferenceTestTrait;

  /**
   * @var \Drupal\filter\FilterFormatInterface
   */
  protected $testFormat;


  /**
   * Vocabulary for testing chained token support.
   *
   * @var \Drupal\taxonomy\VocabularyInterface
   */
  protected $vocabulary;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['node', 'text', 'field', 'filter', 'contact', 'options', 'taxonomy'];

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');

    // Create the article content type with a text field.
    $node_type = NodeType::create([
      'type' => 'article',
    ]);
    $node_type->save();

    $field_storage = FieldStorageConfig::create([
      'field_name' => 'test_field',
      'entity_type' => 'node',
      'type' => 'text',
    ]);
    $field_storage->save();

    $field = FieldConfig::create([
      'field_name' => 'test_field',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Test field',
    ]);
    $field->save();

    // Create a reference field with the same name on user.
    $field_storage = FieldStorageConfig::create([
      'field_name' => 'test_field',
      'entity_type' => 'user',
      'type' => 'entity_reference',
    ]);
    $field_storage->save();

    $field = FieldConfig::create([
      'field_name' => 'test_field',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Test field',
    ]);
    $field->save();

    $this->testFormat = FilterFormat::create([
      'format' => 'test',
      'weight' => 1,
      'filters' => [
        'filter_html_escape' => ['status' => TRUE],
      ],
    ]);
    $this->testFormat->save();

    // Create a multi-value list_string field.
    $field_storage = FieldStorageConfig::create([
      'field_name' => 'test_list',
      'entity_type' => 'node',
      'type' => 'list_string',
      'cardinality' => 2,
      'settings' => [
        'allowed_values' => [
          'key1' => 'value1',
          'key2' => 'value2',
        ]
      ],
    ]);
    $field_storage->save();

    $this->field = FieldConfig::create([
      'field_name' => 'test_list',
      'entity_type' => 'node',
      'bundle' => 'article',
    ])->save();

    // Add a node reference field.
    $this->createEntityReferenceField('node', 'article', 'test_reference', 'Test reference', 'node');

    // Add a taxonomy term reference field.
    $this->vocabulary = $this->createVocabulary();
    $this->createEntityReferenceField(
      'node',
      'article',
      'test_term_reference',
      'Test term reference',
      'taxonomy_term',
      'default:taxonomy_term',
      [
        'target_bundles' => [
          $this->vocabulary->id() => $this->vocabulary->id(),
        ],
      ]
    );

    // Add a field to terms of the created vocabulary.
    $storage = FieldStorageConfig::create([
      'field_name' => 'term_field',
      'entity_type' => 'taxonomy_term',
      'type' => 'text',
    ]);
    $storage->save();
    $field = FieldConfig::create([
      'field_name' => 'term_field',
      'entity_type' => 'taxonomy_term',
      'bundle' => $this->vocabulary->id(),
    ]);
    $field->save();
  }

  /**
   * Tests [entity:field_name] tokens.
   */
  public function testEntityFieldTokens() {
    // Create a node with a value in its fields and test its tokens.
    $entity = Node::create([
      'title' => 'Test node title',
      'type' => 'article',
      'test_field' => [
        'value' => 'foo',
        'format' => $this->testFormat->id(),
      ],
      'test_list' => [
        'value1',
        'value2',
      ],
    ]);
    $entity->save();
    $this->assertTokens('node', ['node' => $entity], [
      'test_field' => Markup::create('foo'),
      'test_field:0' => Markup::create('foo'),
      'test_field:0:value' => 'foo',
      'test_field:value' => 'foo',
      'test_field:0:format' => $this->testFormat->id(),
      'test_field:format' => $this->testFormat->id(),
      'test_list:0' => Markup::create('value1'),
      'test_list:1' => Markup::create('value2'),
      'test_list:0:value' => Markup::create('value1'),
      'test_list:value' => Markup::create('value1'),
      'test_list:1:value' => Markup::create('value2'),
    ]);

    // Verify that no third token was generated for the list_string field.
    $this->assertNoTokens('node', ['node' => $entity], [
      'test_list:2',
      'test_list:2:value',
    ]);

    // Test the test_list token metadata.
    $tokenService = \Drupal::service('token');
    $token_info = $tokenService->getTokenInfo('node', 'test_list');
    $this->assertEqual($token_info['name'], 'test_list');
    $this->assertEqual($token_info['module'], 'token');
    $this->assertEqual($token_info['type'], 'list<node-test_list>');
    $typeInfo = $tokenService->getTypeInfo('list<node-test_list>');
    $this->assertEqual($typeInfo['name'], 'List of test_list values');
    $this->assertEqual($typeInfo['type'], 'list<node-test_list>');

    // Create a node type that does not have test_field field.
    $node_type = NodeType::create([
      'type' => 'page',
    ]);
    $node_type->save();

    $node_without_test_field = Node::create([
      'title' => 'Node without test_field',
      'type' => 'page',
    ]);
    $node_without_test_field->save();

    // Ensure that trying to generate tokens for a non-existing field does not
    // throw an exception.
    $this->assertNoTokens('node', ['node' => $node_without_test_field], ['test_field']);

    // Create a node without a value in the text field and test its token.
    $entity = Node::create([
      'title' => 'Test node title',
      'type' => 'article',
    ]);
    $entity->save();

    $this->assertNoTokens('node', ['node' => $entity], [
      'test_field',
    ]);
  }

  /**
   * Tests the token metadata for a field token.
   */
  public function testFieldTokenInfo() {
    /** @var \Drupal\token\Token $tokenService */
    $tokenService = \Drupal::service('token');

    // Test the token info of the text field of the artcle content type.
    $token_info = $tokenService->getTokenInfo('node', 'test_field');
    $this->assertEqual($token_info['name'], 'Test field', 'The token info name is correct.');
    $this->assertEqual($token_info['description'], 'Text (formatted) field.', 'The token info description is correct.');
    $this->assertEqual($token_info['module'], 'token', 'The token info module is correct.');

    // Now create two more content types that share the field but the last
    // of them sets a different label. This should show an alternative label
    // at the token info.
    $node_type = NodeType::create([
      'type' => 'article2',
    ]);
    $node_type->save();
    $field = FieldConfig::create([
      'field_name' => 'test_field',
      'entity_type' => 'node',
      'bundle' => 'article2',
      'label' => 'Test field',
    ]);
    $field->save();

    $node_type = NodeType::create([
      'type' => 'article3',
    ]);
    $node_type->save();
    $field = FieldConfig::create([
      'field_name' => 'test_field',
      'entity_type' => 'node',
      'bundle' => 'article3',
      'label' => 'Different test field',
    ]);
    $field->save();

    $token_info = $tokenService->getTokenInfo('node', 'test_field');
    $this->assertEqual($token_info['name'], 'Test field', 'The token info name is correct.');
    $this->assertEqual((string) $token_info['description'], 'Text (formatted) field. Also known as <em class="placeholder">Different test field</em>.', 'When a field is used in several bundles with different labels, this is noted at the token info description.');
    $this->assertEqual($token_info['module'], 'token', 'The token info module is correct.');
    $this->assertEqual($token_info['type'], 'node-test_field', 'The field property token info type is correct.');

    // Test field property token info.
    $token_info = $tokenService->getTokenInfo('node-test_field', 'value');
    $this->assertEqual($token_info['name'], 'Text', 'The field property token info name is correct.');
    // This particular field property description happens to be empty.
    $this->assertEqual((string) $token_info['description'], '', 'The field property token info description is correct.');
    $this->assertEqual($token_info['module'], 'token', 'The field property token info module is correct.');
  }

  /**
   * Test tokens on node with the token view mode overriding default formatters.
   */
  public function testTokenViewMode() {
    $value = 'A really long string that should be trimmed by the special formatter on token view we are going to have.';

    // The formatter we are going to use will eventually call Unicode::strlen.
    // This expects that the Unicode has already been explicitly checked, which
    // happens in DrupalKernel. But since that doesn't run in kernel tests, we
    // explicitly call this here.
    Unicode::check();

    // Create a node with a value in the text field and test its token.
    $entity = Node::create([
      'title' => 'Test node title',
      'type' => 'article',
      'test_field' => [
        'value' => $value,
        'format' => $this->testFormat->id(),
      ],
    ]);
    $entity->save();

    $this->assertTokens('node', ['node' => $entity], [
      'test_field' => Markup::create($value),
    ]);

    // Now, create a token view mode which sets a different format for
    // test_field. When replacing tokens, this formatter should be picked over
    // the default formatter for the field type.
    // @see field_tokens().
    $view_mode = EntityViewMode::create([
      'id' => 'node.token',
      'targetEntityType' => 'node',
    ]);
    $view_mode->save();
    $entity_display = entity_get_display('node', 'article', 'token');
    $entity_display->setComponent('test_field', [
      'type' => 'text_trimmed',
      'settings' => [
        'trim_length' => 50,
      ]
    ]);
    $entity_display->save();

    $this->assertTokens('node', ['node' => $entity], [
      'test_field' => Markup::create(substr($value, 0, 50)),
    ]);
  }

  /**
   * Test that tokens are properly created for an entity's base fields.
   */
  public function testBaseFieldTokens() {
    // Create a new contact_message entity and verify that tokens are generated
    // for its base fields. The contact_message entity type is used because it
    // provides no tokens by default.
    $contact_form = ContactForm::create([
      'id' => 'form_id',
    ]);
    $contact_form->save();

    $entity = Message::create([
      'contact_form' => 'form_id',
      'uuid' => '123',
      'langcode' => 'en',
      'name' => 'Test name',
      'mail' => 'Test mail',
      'subject' => 'Test subject',
      'message' => 'Test message',
      'copy' => FALSE,
    ]);
    $entity->save();
    $this->assertTokens('contact_message', ['contact_message' => $entity], [
      'uuid' => Markup::create('123'),
      'langcode' => Markup::create('English'),
      'name' => Markup::create('Test name'),
      'mail' => Markup::create('Test mail'),
      'subject' => Markup::create('Test subject'),
      'message' => Markup::create('Test message'),
      'copy' => 'Off',
    ]);

    // Test the metadata of one of the tokens.
    $tokenService = \Drupal::service('token');
    $token_info = $tokenService->getTokenInfo('contact_message', 'subject');
    $this->assertEquals($token_info['name'], 'Subject');
    $this->assertEquals($token_info['description'], 'Text (plain) field.');
    $this->assertEquals($token_info['module'], 'token');

    // Verify that node entity type doesn't have a uid token.
    $this->assertNull($tokenService->getTokenInfo('node', 'uid'));
  }

  /*
   * Tests chaining entity reference tokens.
   */
  public function testEntityReferenceTokens() {
    $reference = Node::create([
      'title' => 'Test node to reference',
      'type' => 'article',
      'test_field' => [
        'value' => 'foo',
        'format' => $this->testFormat->id(),
      ]
    ]);
    $reference->save();
    $term_reference_field_value = $this->randomString();
    $term_reference = $this->createTerm($this->vocabulary, [
      'name' => 'Term to reference',
      'term_field' => [
        'value' => $term_reference_field_value,
        'format' => $this->testFormat->id(),
      ],
    ]);
    $entity = Node::create([
      'title' => 'Test entity reference',
      'type' => 'article',
      'test_reference' => ['target_id' => $reference->id()],
      'test_term_reference' => ['target_id' => $term_reference->id()],
    ]);
    $entity->save();

    $this->assertTokens('node', ['node' => $entity], [
      'test_reference:entity:title' => Markup::create('Test node to reference'),
      'test_reference:entity:test_field' => Markup::create('foo'),
      'test_term_reference:entity:term_field' => Html::escape($term_reference_field_value),
      'test_reference:target_id' => $reference->id(),
      'test_term_reference:target_id' => $term_reference->id(),
      'test_term_reference:entity:url:path' => '/' . $term_reference->toUrl('canonical')->getInternalPath(),
      // Expects the entity's label to be returned for :entity tokens.
      'test_reference:entity' => $reference->label(),
      'test_term_reference:entity' => $term_reference->label(),
    ]);

    // Test some non existent tokens.
    $this->assertNoTokens('node', ['node' => $entity], [
      'test_reference:1:title',
      'test_reference:entity:does_not_exist',
      'test_reference:does_not:exist',
      'test_term_reference:does_not_exist',
      'test_term_reference:does:not:exist',
      'test_term_reference:does_not_exist:0',
      'non_existing_field:entity:title',
    ]);

    /** @var \Drupal\token\Token $token_service */
    $token_service = \Drupal::service('token');

    $token_info = $token_service->getTokenInfo('node', 'test_reference');
    $this->assertEquals('Test reference', $token_info['name']);
    $this->assertEquals('Entity reference field.', (string) $token_info['description']);
    $this->assertEquals('token', $token_info['module']);
    $this->assertEquals('node-test_reference', $token_info['type']);

    // Test target_id field property token info.
    $token_info = $token_service->getTokenInfo('node-test_reference', 'target_id');
    $this->assertEquals('Content ID', $token_info['name']);
    $this->assertEquals('token', $token_info['module']);
    $this->assertEquals('token', $token_info['module']);

    // Test entity field property token info.
    $token_info = $token_service->getTokenInfo('node-test_reference', 'entity');
    $this->assertEquals('Content', $token_info['name']);
    $this->assertEquals('The referenced entity', $token_info['description']);
    $this->assertEquals('token', $token_info['module']);
    $this->assertEquals('node', $token_info['type']);

    // Test entity field property token info of the term reference.
    $token_info = $token_service->getTokenInfo('node-test_term_reference', 'entity');
    $this->assertEquals('Taxonomy term', $token_info['name']);
    $this->assertEquals('The referenced entity', $token_info['description']);
    $this->assertEquals('token', $token_info['module']);
    $this->assertEquals('term', $token_info['type']);

  }

  /**
   * Tests support for cardinality > 1 for entity reference tokens.
   */
  public function testEntityReferenceTokensCardinality() {
    /** @var \Drupal\field\FieldStorageConfigInterface $storage */
    $storage = FieldStorageConfig::load('node.test_term_reference');
    $storage->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $storage->save();

    // Add a few terms.
    $terms = [];
    $terms_value = [];
    foreach (range(1, 3) as $i) {
      $terms_value[$i] = $this->randomString();
      $terms[$i] = $this->createTerm($this->vocabulary, [
        'name' => $this->randomString(),
        'term_field' => [
          'value' => $terms_value[$i],
          'format' => $this->testFormat->id(),
        ],
      ]);
    }

    $entity = Node::create([
      'title' => 'Test multivalue chained tokens',
      'type' => 'article',
      'test_term_reference' => [
        ['target_id' => $terms[1]->id()],
        ['target_id' => $terms[2]->id()],
        ['target_id' => $terms[3]->id()],
      ],
    ]);
    $entity->save();

    $this->assertTokens('node', ['node' => $entity], [
      'test_term_reference:0:entity:term_field' => Html::escape($terms[1]->term_field->value),
      'test_term_reference:1:entity:term_field' => Html::escape($terms[2]->term_field->value),
      'test_term_reference:2:entity:term_field' => Html::escape($terms[3]->term_field->value),
      'test_term_reference:0:target_id' => $terms[1]->id(),
      'test_term_reference:1:target_id' => $terms[2]->id(),
      'test_term_reference:2:target_id' => $terms[3]->id(),
      // Expects the entity's label to be returned for :entity tokens.
      'test_term_reference:0:entity' => $terms[1]->label(),
      'test_term_reference:1:entity' => $terms[2]->label(),
      'test_term_reference:2:entity' => $terms[3]->label(),
      // To make sure tokens without an explicit delta can also be replaced in
      // the same token replacement call.
      'test_term_reference:entity:term_field' => Html::escape($terms[1]->term_field->value),
      'test_term_reference:target_id' => $terms[1]->id(),
    ]);

    // Test some non existent tokens.
    $this->assertNoTokens('node', ['node' => $entity], [
      'test_term_reference:3:term_field',
      'test_term_reference:0:does_not_exist',
      'test_term_reference:1:does:not:exist',
      'test_term_reference:1:2:does_not_exist',
    ]);
  }

}
