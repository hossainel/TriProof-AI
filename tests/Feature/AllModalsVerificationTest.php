<?php

namespace Tests\Feature;

use App\Enums\SerialStatus;
use App\Models\Factory;
use App\Models\RecallEvent;
use App\Models\SerialRegistry;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\TriProofFixtures;
use Tests\TestCase;

class AllModalsVerificationTest extends TestCase
{
    use RefreshDatabase;
    use TriProofFixtures;

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function dialogXpath(string $html, string $dialogId): DOMXPath
    {
        $xpath = $this->xpath($html);
        $dialog = $xpath->query("//dialog[@id='{$dialogId}']")->item(0);

        $this->assertInstanceOf(DOMElement::class, $dialog, "Dialog [{$dialogId}] is missing.");

        return $xpath;
    }

    private function bodyOpenDialog(string $html): ?string
    {
        $body = $this->xpath($html)->query('//body')->item(0);

        if (! $body instanceof DOMElement || ! $body->hasAttribute('data-open-dialog')) {
            return null;
        }

        $value = trim($body->getAttribute('data-open-dialog'));

        return $value === '' ? null : $value;
    }

    private function formValue(string $html, string $dialogId, string $fieldName): ?string
    {
        $xpath = $this->xpath($html);
        $nodes = $xpath->query("//dialog[@id='{$dialogId}']//*[@name='{$fieldName}']");

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);

            if ($tag === 'input' || $tag === 'select') {
                if ($tag === 'select') {
                    $selected = $xpath->query('.//option[@selected]', $node)->item(0);

                    return $selected instanceof DOMElement ? $selected->getAttribute('value') : null;
                }

                return $node->getAttribute('value');
            }

            if ($tag === 'textarea') {
                return $node->textContent;
            }
        }

        return null;
    }

    private function assertModalContract(string $html, array $expectedDialogIds, bool $adminPage = false): void
    {
        $xpath = $this->xpath($html);
        $dialogClass = 'contains(concat(" ", normalize-space(@class), " "), " tp-dialog ")';

        foreach ($expectedDialogIds as $dialogId) {
            $this->assertSame(
                1,
                $xpath->query("//dialog[{$dialogClass} and @id='{$dialogId}']")->length,
                "Expected exactly one [{$dialogId}] dialog.",
            );
        }

        $seenIds = [];
        foreach ($xpath->query('//*[@id]') as $node) {
            $id = $node->getAttribute('id');
            $this->assertArrayNotHasKey($id, $seenIds, "Duplicate element id [{$id}].");
            $seenIds[$id] = true;
        }

        $this->assertSame(0, $xpath->query("//dialog[{$dialogClass} and @open]")->length, 'No modal may render with a hardcoded open attribute.');

        foreach ($xpath->query('//*[@data-open-dialog]') as $trigger) {
            $target = trim($trigger->getAttribute('data-open-dialog'));
            $this->assertNotSame('', $target, 'Every dialog trigger must declare a target.');
            $this->assertSame(
                1,
                $xpath->query("//dialog[@id='{$target}']")->length,
                "Dialog trigger target [{$target}] does not exist exactly once.",
            );
        }

        foreach ($xpath->query("//dialog[{$dialogClass}]") as $dialog) {
            $dialogId = $dialog->getAttribute('id');

            foreach ($xpath->query('.//*[@data-close-dialog]', $dialog) as $closeButton) {
                $this->assertSame(
                    'button',
                    strtolower($closeButton->getAttribute('type')),
                    "Close control in [{$dialogId}] must be a button with type=\"button\".",
                );
            }

            $forms = $xpath->query('.//form', $dialog);

            if ($forms->length === 0) {
                continue;
            }

            $this->assertSame(1, $forms->length, "Dialog [{$dialogId}] must contain exactly one form.");
            $form = $forms->item(0);

            $this->assertSame('post', strtolower($form->getAttribute('method')), "Form in [{$dialogId}] must use POST.");
            $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form)->length, "Form in [{$dialogId}] is missing CSRF protection.");
            $this->assertSame(1, $xpath->query('.//button[@type="submit"]', $form)->length, "Form in [{$dialogId}] must have one submit button.");

            $dialogInputs = $xpath->query('.//input[@name="dialog_id"]', $form);
            $this->assertSame(1, $dialogInputs->length, "Form in [{$dialogId}] is missing one hidden dialog_id.");
            $this->assertSame($dialogId, $dialogInputs->item(0)->getAttribute('value'), "Form dialog_id in [{$dialogId}] must match the dialog id exactly.");

            if ($adminPage) {
                $activeTabInputs = $xpath->query('.//input[@name="active_tab"]', $form);
                $this->assertSame(1, $activeTabInputs->length, "Admin form in [{$dialogId}] is missing one hidden active_tab.");
                $this->assertContains(
                    $activeTabInputs->item(0)->getAttribute('value'),
                    ['admin-buyers', 'admin-factories', 'admin-operators', 'admin-settings'],
                    "Admin form in [{$dialogId}] has an invalid active_tab.",
                );
            }
        }
    }

    private function factoryRow(string $code, string $name = 'Modal Plant'): Factory
    {
        return Factory::query()->create([
            'code' => $code,
            'name_en' => $name,
            'is_active' => true,
        ]);
    }

    private function signInAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $this->from(route('admin.manage'));

        return $admin;
    }

    private function assertModalSuccess(TestResponse $response, string $returnUrl): void
    {
        $response->assertRedirect($returnUrl);
        $response->assertSessionHas('status');
        $response->assertSessionHasNoErrors();
        $response->assertSessionMissing('_old_input.dialog_id');
        $response->assertSessionMissing('open_dialog');
        $response->assertSessionMissing('dialog_id');

        $page = $this->get($returnUrl)->assertOk();
        $this->assertNull($this->bodyOpenDialog($page->getContent()), 'A successful save must not auto-open a modal.');
    }

    private function assertModalReopens(
        string $method,
        string $url,
        array $payload,
        string $returnUrl,
        string $dialogId,
    ): string {
        $response = $this->from($returnUrl)->{$method}($url, $payload);

        $response->assertRedirect($returnUrl);
        $response->assertSessionHasErrors();
        $response->assertSessionHasInput('dialog_id', $dialogId);

        $page = $this->get($returnUrl)->assertOk();
        $html = $page->getContent();

        $this->assertSame($dialogId, $this->bodyOpenDialog($html), 'The failing dialog must auto-open.');
        $this->assertSame(
            1,
            $this->dialogXpath($html, $dialogId)->query(
                "//dialog[@id='{$dialogId}']//div[contains(@class, 'bg-rose-50')][contains(., '".__('triproof.common.validation_errors')."')]"
            )->length,
            "Dialog [{$dialogId}] must render an inline validation error summary.",
        );

        return $html;
    }

    public function test_dashboard_dialog_markup_contract_is_satisfied(): void
    {
        [$order, $buyer] = $this->makeOrder(12);
        $this->generateSerials($order, $buyer, 4, 'M01', 'B01');
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('dashboard'))->assertOk();

        $this->assertModalContract($response->getContent(), [
            'create-order-dialog',
            'generate-dialog',
            'pack-dialog',
            'recall-dialog',
            'merkle-dialog',
        ]);
        $this->assertNull($this->bodyOpenDialog($response->getContent()));
    }

    public function test_admin_manage_dialog_markup_contract_is_satisfied(): void
    {
        $this->signInAsAdmin();
        $buyer = User::factory()->buyer()->create();
        $factory = $this->factoryRow('BD71');
        $operator = User::factory()->factory($factory->code)->create();

        $response = $this->get(route('admin.manage'))->assertOk();

        $this->assertModalContract($response->getContent(), [
            'add-buyer-dialog',
            'add-factory-dialog',
            'add-operator-dialog',
            'edit-buyer-'.$buyer->id,
            'edit-factory-'.$factory->id,
            'edit-operator-'.$operator->id,
        ], true);
        $this->assertNull($this->bodyOpenDialog($response->getContent()));
    }

    public function test_add_buyer_modal_success_persists_and_closes(): void
    {
        $this->signInAsAdmin();

        $response = $this->post(route('admin.buyers.store'), [
            'name' => 'Modal Buyer',
            'email' => 'modal-buyer@example.test',
            'password' => 'Correct!12345',
            'language_pref' => 'en',
            'dialog_id' => 'add-buyer-dialog',
            'active_tab' => 'admin-buyers',
        ]);

        $this->assertModalSuccess($response, route('admin.manage'));
        $this->assertDatabaseHas('users', [
            'name' => 'Modal Buyer',
            'email' => 'modal-buyer@example.test',
            'role' => 'global_buyer',
        ]);
    }

    public function test_edit_buyer_modal_success_persists_and_closes(): void
    {
        $this->signInAsAdmin();
        $buyer = User::factory()->buyer()->create(['name' => 'Original Buyer']);

        $response = $this->put(route('admin.buyers.update', $buyer), [
            'name' => 'Updated Buyer',
            'email' => $buyer->email,
            'language_pref' => 'bn',
            'dialog_id' => 'edit-buyer-'.$buyer->id,
            'active_tab' => 'admin-buyers',
        ]);

        $this->assertModalSuccess($response, route('admin.manage'));
        $this->assertDatabaseHas('users', ['id' => $buyer->id, 'name' => 'Updated Buyer', 'language_pref' => 'bn']);
    }

    public function test_add_factory_modal_success_persists_and_closes(): void
    {
        $this->signInAsAdmin();

        $response = $this->post(route('admin.factories.store'), [
            'code' => 'BD81',
            'name_en' => 'Modal Factory',
            'is_active' => '1',
            'dialog_id' => 'add-factory-dialog',
            'active_tab' => 'admin-factories',
        ]);

        $this->assertModalSuccess($response, route('admin.manage'));
        $this->assertDatabaseHas('factories', ['code' => 'BD81', 'name_en' => 'Modal Factory', 'is_active' => true]);
    }

    public function test_edit_factory_modal_success_persists_and_closes(): void
    {
        $this->signInAsAdmin();
        $factory = $this->factoryRow('BD82', 'Original Factory');

        $response = $this->put(route('admin.factories.update', $factory), [
            'name_en' => 'Updated Factory',
            'is_active' => '1',
            'dialog_id' => 'edit-factory-'.$factory->id,
            'active_tab' => 'admin-factories',
        ]);

        $this->assertModalSuccess($response, route('admin.manage'));
        $this->assertDatabaseHas('factories', ['id' => $factory->id, 'name_en' => 'Updated Factory']);
    }

    public function test_add_operator_modal_success_persists_and_closes(): void
    {
        $this->signInAsAdmin();
        $factory = $this->factoryRow('BD83');

        $response = $this->post(route('admin.operators.store'), [
            'name' => 'Modal Operator',
            'email' => 'modal-operator@example.test',
            'password' => 'Correct!12345',
            'factory_id' => $factory->code,
            'language_pref' => 'en',
            'dialog_id' => 'add-operator-dialog',
            'active_tab' => 'admin-operators',
        ]);

        $this->assertModalSuccess($response, route('admin.manage'));
        $this->assertDatabaseHas('users', [
            'name' => 'Modal Operator',
            'email' => 'modal-operator@example.test',
            'role' => 'factory_qa',
            'factory_id' => 'BD83',
        ]);
    }

    public function test_edit_operator_modal_success_persists_and_closes(): void
    {
        $this->signInAsAdmin();
        $factory = $this->factoryRow('BD84');
        $operator = User::factory()->factory($factory->code)->create(['name' => 'Original Operator']);

        $response = $this->put(route('admin.operators.update', $operator), [
            'name' => 'Updated Operator',
            'email' => $operator->email,
            'factory_id' => $factory->code,
            'language_pref' => 'en',
            'dialog_id' => 'edit-operator-'.$operator->id,
            'active_tab' => 'admin-operators',
        ]);

        $this->assertModalSuccess($response, route('admin.manage'));
        $this->assertDatabaseHas('users', ['id' => $operator->id, 'name' => 'Updated Operator']);
    }

    public function test_create_order_modal_success_persists_and_closes(): void
    {
        $this->signInAsAdmin();
        $buyer = User::factory()->buyer()->create();
        $factory = $this->factoryRow('BD85');

        $response = $this->post(route('orders.store'), [
            'po_number' => 'PO-MODAL-001',
            'buyer_id' => $buyer->id,
            'factory_id' => $factory->code,
            'authorized_quota' => 20,
            'factory_expiry_days' => 30,
            'retail_expiry_days' => 180,
            'dialog_id' => 'create-order-dialog',
        ]);

        $this->assertModalSuccess($response, route('dashboard'));
        $this->assertDatabaseHas('purchase_orders', [
            'po_number' => 'PO-MODAL-001',
            'buyer_id' => $buyer->id,
            'factory_id' => 'BD85',
            'authorized_quota' => 20,
        ]);
    }

    public function test_generate_modal_success_persists_and_closes(): void
    {
        [$order, $buyer] = $this->makeOrder(10);

        $response = $this->actingAs($buyer)
            ->from(route('dashboard'))
            ->post(route('orders.generate', $order), [
                'machine_id' => 'M77',
                'batch_id' => 'B77',
                'unit_start' => 1,
                'count' => 3,
                'prod_date' => now()->toDateString(),
                'dialog_id' => 'generate-dialog',
            ]);

        $this->assertModalSuccess($response, route('dashboard'));
        $this->assertSame(3, SerialRegistry::query()->where('po_id', $order->id)->count());
        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id, 'generated_count' => 3]);
    }

    public function test_pack_modal_success_persists_and_closes(): void
    {
        [$order, $buyer, $factory] = $this->makeOrder(10);
        $this->generateSerials($order, $buyer, 4, 'M01', 'B01');

        $response = $this->actingAs($factory)
            ->from(route('dashboard'))
            ->post(route('orders.pack', $order), [
                'machine_id' => 'M01',
                'batch_id' => 'B01',
                'unit_start' => 1,
                'unit_end' => 2,
                'dialog_id' => 'pack-dialog',
            ]);

        $this->assertModalSuccess($response, route('dashboard'));
        $this->assertSame(2, SerialRegistry::query()
            ->where('po_id', $order->id)
            ->where('status', SerialStatus::Packed->value)
            ->count());
    }

    public function test_recall_modal_success_persists_and_closes(): void
    {
        [$order, $buyer] = $this->makeOrder(10);
        $this->generateSerials($order, $buyer, 3, 'M01', 'B01');

        $response = $this->actingAs($buyer)
            ->from(route('dashboard'))
            ->post(route('recall.trigger'), [
                'factory_id' => 'DHK04',
                'machine_id' => 'M01',
                'batch_id' => 'B01',
                'reason' => 'Modal recall drill',
                'dialog_id' => 'recall-dialog',
            ]);

        $this->assertModalSuccess($response, route('dashboard'));
        $this->assertSame(3, SerialRegistry::query()
            ->where('po_id', $order->id)
            ->where('status', SerialStatus::Recalled->value)
            ->count());
        $this->assertDatabaseHas('recall_events', [
            'po_id' => $order->id,
            'factory_id' => 'DHK04',
            'machine_id' => 'M01',
            'batch_id' => 'B01',
            'affected_count' => 3,
        ]);
        $this->assertSame(1, RecallEvent::query()->count());
    }

    public function test_invalid_add_buyer_reopens_only_its_dialog(): void
    {
        $this->signInAsAdmin();
        $otherBuyer = User::factory()->buyer()->create(['name' => 'Other Buyer']);

        $html = $this->assertModalReopens('post', route('admin.buyers.store'), [
            'name' => 'Leaked Buyer',
            'email' => 'not-an-email',
            'password' => 'short',
            'language_pref' => 'en',
            'dialog_id' => 'add-buyer-dialog',
            'active_tab' => 'admin-buyers',
        ], route('admin.manage'), 'add-buyer-dialog');

        $this->assertSame('Leaked Buyer', $this->formValue($html, 'add-buyer-dialog', 'name'));
        $this->assertSame('', $this->formValue($html, 'add-operator-dialog', 'name'));
        $this->assertSame('Other Buyer', $this->formValue($html, 'edit-buyer-'.$otherBuyer->id, 'name'));
    }

    public function test_invalid_edit_buyer_reopens_only_its_dialog(): void
    {
        $this->signInAsAdmin();
        $buyer = User::factory()->buyer()->create(['name' => 'Buyer One']);
        $otherBuyer = User::factory()->buyer()->create(['name' => 'Buyer Two']);

        $html = $this->assertModalReopens('put', route('admin.buyers.update', $buyer), [
            'name' => 'Leaked Edit Buyer',
            'email' => 'not-an-email',
            'language_pref' => 'en',
            'dialog_id' => 'edit-buyer-'.$buyer->id,
            'active_tab' => 'admin-buyers',
        ], route('admin.manage'), 'edit-buyer-'.$buyer->id);

        $this->assertSame('Leaked Edit Buyer', $this->formValue($html, 'edit-buyer-'.$buyer->id, 'name'));
        $this->assertSame('Buyer Two', $this->formValue($html, 'edit-buyer-'.$otherBuyer->id, 'name'));
        $this->assertSame('', $this->formValue($html, 'add-buyer-dialog', 'name'));
        $this->assertSame('', $this->formValue($html, 'add-operator-dialog', 'name'));
    }

    public function test_invalid_add_factory_reopens_only_its_dialog(): void
    {
        $this->signInAsAdmin();
        $factory = $this->factoryRow('BD86', 'Existing Factory');

        $html = $this->assertModalReopens('post', route('admin.factories.store'), [
            'code' => 'BD99',
            'name_en' => 'Leaked Factory',
            'contact_email' => 'not-an-email',
            'is_active' => '1',
            'dialog_id' => 'add-factory-dialog',
            'active_tab' => 'admin-factories',
        ], route('admin.manage'), 'add-factory-dialog');

        $this->assertSame('Leaked Factory', $this->formValue($html, 'add-factory-dialog', 'name_en'));
        $this->assertSame('Existing Factory', $this->formValue($html, 'edit-factory-'.$factory->id, 'name_en'));
    }

    public function test_invalid_edit_factory_reopens_only_its_dialog(): void
    {
        $this->signInAsAdmin();
        $factory = $this->factoryRow('BD87', 'Factory One');
        $otherFactory = $this->factoryRow('BD88', 'Factory Two');

        $html = $this->assertModalReopens('put', route('admin.factories.update', $factory), [
            'name_en' => 'Leaked Edit Factory',
            'contact_email' => 'not-an-email',
            'is_active' => '1',
            'dialog_id' => 'edit-factory-'.$factory->id,
            'active_tab' => 'admin-factories',
        ], route('admin.manage'), 'edit-factory-'.$factory->id);

        $this->assertSame('Leaked Edit Factory', $this->formValue($html, 'edit-factory-'.$factory->id, 'name_en'));
        $this->assertSame('Factory Two', $this->formValue($html, 'edit-factory-'.$otherFactory->id, 'name_en'));
        $this->assertSame('', $this->formValue($html, 'add-factory-dialog', 'name_en'));
    }

    public function test_invalid_add_operator_reopens_only_its_dialog(): void
    {
        $this->signInAsAdmin();
        $factory = $this->factoryRow('BD89');
        $operator = User::factory()->factory($factory->code)->create(['name' => 'Existing Operator']);

        $html = $this->assertModalReopens('post', route('admin.operators.store'), [
            'name' => 'Leaked Operator',
            'email' => 'not-an-email',
            'password' => 'short',
            'factory_id' => $factory->code,
            'language_pref' => 'en',
            'dialog_id' => 'add-operator-dialog',
            'active_tab' => 'admin-operators',
        ], route('admin.manage'), 'add-operator-dialog');

        $this->assertSame('Leaked Operator', $this->formValue($html, 'add-operator-dialog', 'name'));
        $this->assertSame('BD89', $this->formValue($html, 'add-operator-dialog', 'factory_id'));
        $this->assertSame('', $this->formValue($html, 'add-buyer-dialog', 'name'));
        $this->assertSame('Existing Operator', $this->formValue($html, 'edit-operator-'.$operator->id, 'name'));
    }

    public function test_invalid_edit_operator_reopens_only_its_dialog(): void
    {
        $this->signInAsAdmin();
        $factory = $this->factoryRow('BD90');
        $operator = User::factory()->factory($factory->code)->create(['name' => 'Operator One']);
        $otherOperator = User::factory()->factory($factory->code)->create(['name' => 'Operator Two']);

        $html = $this->assertModalReopens('put', route('admin.operators.update', $operator), [
            'name' => 'Leaked Edit Operator',
            'email' => 'not-an-email',
            'factory_id' => $factory->code,
            'language_pref' => 'en',
            'dialog_id' => 'edit-operator-'.$operator->id,
            'active_tab' => 'admin-operators',
        ], route('admin.manage'), 'edit-operator-'.$operator->id);

        $this->assertSame('Leaked Edit Operator', $this->formValue($html, 'edit-operator-'.$operator->id, 'name'));
        $this->assertSame('BD90', $this->formValue($html, 'edit-operator-'.$operator->id, 'factory_id'));
        $this->assertSame('Operator Two', $this->formValue($html, 'edit-operator-'.$otherOperator->id, 'name'));
        $this->assertSame('', $this->formValue($html, 'add-operator-dialog', 'name'));
    }

    public function test_invalid_create_order_reopens_only_its_dialog(): void
    {
        $this->signInAsAdmin();
        $buyer = User::factory()->buyer()->create();

        $html = $this->assertModalReopens('post', route('orders.store'), [
            'po_number' => 'PO-LEAK-001',
            'buyer_id' => $buyer->id,
            'factory_id' => 'NOPE',
            'authorized_quota' => 12,
            'factory_expiry_days' => 30,
            'retail_expiry_days' => 180,
            'dialog_id' => 'create-order-dialog',
        ], route('dashboard'), 'create-order-dialog');

        $this->assertSame('PO-LEAK-001', $this->formValue($html, 'create-order-dialog', 'po_number'));
        $this->assertSame('', $this->formValue($html, 'generate-dialog', 'machine_id'));
        $this->assertSame('', $this->formValue($html, 'pack-dialog', 'machine_id'));
        $this->assertSame('', $this->formValue($html, 'recall-dialog', 'factory_id'));
    }

    public function test_invalid_generate_reopens_only_its_dialog(): void
    {
        [$order, $buyer] = $this->makeOrder(10);
        $this->actingAs($buyer);

        $html = $this->assertModalReopens('post', route('orders.generate', $order), [
            'machine_id' => 'M77',
            'batch_id' => 'B77',
            'unit_start' => 7,
            'count' => 0,
            'prod_date' => now()->toDateString(),
            'dialog_id' => 'generate-dialog',
        ], route('dashboard'), 'generate-dialog');

        $this->assertSame('M77', $this->formValue($html, 'generate-dialog', 'machine_id'));
        $this->assertSame('B77', $this->formValue($html, 'generate-dialog', 'batch_id'));
        $this->assertSame('7', $this->formValue($html, 'generate-dialog', 'unit_start'));
        $this->assertSame('', $this->formValue($html, 'pack-dialog', 'machine_id'));
        $this->assertSame('', $this->formValue($html, 'recall-dialog', 'machine_id'));
    }

    public function test_invalid_pack_reopens_only_its_dialog(): void
    {
        [$order, $buyer, $factory] = $this->makeOrder(10);
        $this->generateSerials($order, $buyer, 3, 'M01', 'B01');
        $this->actingAs($factory);

        $html = $this->assertModalReopens('post', route('orders.pack', $order), [
            'machine_id' => 'M88',
            'batch_id' => 'B88',
            'unit_start' => 1,
            'unit_end' => 2,
            'dialog_id' => 'pack-dialog',
        ], route('dashboard'), 'pack-dialog');

        $this->assertSame('M88', $this->formValue($html, 'pack-dialog', 'machine_id'));
        $this->assertSame('B88', $this->formValue($html, 'pack-dialog', 'batch_id'));
        $this->assertSame('', $this->formValue($html, 'generate-dialog', 'machine_id'));
        $this->assertSame('', $this->formValue($html, 'recall-dialog', 'machine_id'));
    }

    public function test_invalid_recall_reopens_only_its_dialog(): void
    {
        [$order, $buyer] = $this->makeOrder(10);
        $this->generateSerials($order, $buyer, 3, 'M01', 'B01');
        $this->actingAs($buyer);

        $html = $this->assertModalReopens('post', route('recall.trigger'), [
            'factory_id' => 'X',
            'machine_id' => '!',
            'batch_id' => 'B01',
            'reason' => 'Leaked recall reason',
            'dialog_id' => 'recall-dialog',
        ], route('dashboard'), 'recall-dialog');

        $this->assertSame('X', $this->formValue($html, 'recall-dialog', 'factory_id'));
        $this->assertSame('!', $this->formValue($html, 'recall-dialog', 'machine_id'));
        $this->assertSame('Leaked recall reason', $this->formValue($html, 'recall-dialog', 'reason'));
        $this->assertSame('', $this->formValue($html, 'generate-dialog', 'machine_id'));
        $this->assertSame('', $this->formValue($html, 'pack-dialog', 'machine_id'));
    }
}
