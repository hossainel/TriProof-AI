<?php

namespace App\Http\Controllers\Web;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBuyerRequest;
use App\Http\Requests\Admin\StoreFactoryRequest;
use App\Http\Requests\Admin\StoreOperatorRequest;
use App\Http\Requests\Admin\UpdateBuyerRequest;
use App\Http\Requests\Admin\UpdateFactoryRequest;
use App\Http\Requests\Admin\UpdateOperatorRequest;
use App\Http\Requests\Admin\UpdateSystemSettingsRequest;
use App\Models\Factory;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AdminDirectoryController extends Controller
{
    public function index(Request $request): View
    {
        session(['active_tab' => $request->input('tab', 'admin-buyers')]);

        return view('admin.manage', [
            'buyers' => User::query()
                ->where('role', UserRole::GlobalBuyer)
                ->orderBy('name')
                ->withCount('purchaseOrders')
                ->get(),
            'factories' => Factory::query()->withCount(['purchaseOrders', 'serials', 'users'])->orderBy('code')->get(),
            'operators' => User::query()
                ->where('role', UserRole::FactoryQa)
                ->orderBy('name')
                ->get(),
            'settings' => array_merge(SystemSetting::DEFAULTS, SystemSetting::allAsArray()),
            'activeTab' => session('active_tab'),
        ]);
    }

    private function adminRedirect(string $message): RedirectResponse
    {
        return redirect()->route('admin.manage', ['tab' => session('active_tab')])
            ->with('success', $message);
    }

    public function storeBuyer(StoreBuyerRequest $request): RedirectResponse
    {
        $data = $request->validated();

        User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password_hash' => Hash::make($data['password']),
            'role' => UserRole::GlobalBuyer,
            'language_pref' => $data['language_pref'],
            'factory_id' => null,
        ]);

        return $this->adminRedirect(__('triproof.admin.flash.buyer_created'));
    }

    public function updateBuyer(UpdateBuyerRequest $request, User $user): RedirectResponse
    {
        $this->ensureRole($user, UserRole::GlobalBuyer);
        $data = $request->validated();

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'language_pref' => $data['language_pref'],
        ]);

        if (! empty($data['password'])) {
            $user->password_hash = Hash::make($data['password']);
        }

        $user->save();

        return $this->adminRedirect(__('triproof.admin.flash.buyer_updated'));
    }

    public function destroyBuyer(User $user): RedirectResponse
    {
        $this->ensureRole($user, UserRole::GlobalBuyer);

        if ($user->purchaseOrders()->exists()) {
            return redirect()->route('admin.manage', ['tab' => session('active_tab')])
                ->withInput(['dialog_id' => 'edit-buyer-'.$user->id])
                ->withErrors(['buyer' => __('triproof.admin.errors.buyer_has_orders')]);
        }

        $user->delete();

        return $this->adminRedirect(__('triproof.admin.flash.buyer_deleted'));
    }

    public function storeFactory(StoreFactoryRequest $request): RedirectResponse
    {
        $request->merge([
            'is_active' => $request->boolean('is_active'),
        ]);
        $data = $request->validated();

        Factory::query()->create([
            'code' => strtoupper($data['code']),
            'name_en' => $data['name_en'],
            'name_bn' => $data['name_bn'] ?? null,
            'location' => $data['location'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return $this->adminRedirect(__('triproof.admin.flash.factory_created'));
    }

    public function updateFactory(UpdateFactoryRequest $request, Factory $factory): RedirectResponse
    {
        $request->merge([
            'is_active' => $request->boolean('is_active'),
        ]);
        $data = $request->validated();

        $factory->update([
            'name_en' => $data['name_en'],
            'name_bn' => $data['name_bn'] ?? null,
            'location' => $data['location'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return $this->adminRedirect(__('triproof.admin.flash.factory_updated'));
    }

    public function destroyFactory(Factory $factory): RedirectResponse
    {
        if ($factory->purchaseOrders()->exists() || $factory->serials()->exists() || $factory->users()->exists()) {
            return redirect()->route('admin.manage', ['tab' => session('active_tab')])
                ->withInput(['dialog_id' => 'edit-factory-'.$factory->id])
                ->withErrors(['factory' => __('triproof.admin.errors.factory_has_references')]);
        }

        $factory->delete();

        return $this->adminRedirect(__('triproof.admin.flash.factory_deleted'));
    }

    public function storeOperator(StoreOperatorRequest $request): RedirectResponse
    {
        $data = $request->validated();

        User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password_hash' => Hash::make($data['password']),
            'role' => UserRole::FactoryQa,
            'language_pref' => $data['language_pref'],
            'factory_id' => strtoupper($data['factory_id']),
        ]);

        return $this->adminRedirect(__('triproof.admin.flash.operator_created'));
    }

    public function updateOperator(UpdateOperatorRequest $request, User $user): RedirectResponse
    {
        $this->ensureRole($user, UserRole::FactoryQa);
        $data = $request->validated();

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'language_pref' => $data['language_pref'],
            'factory_id' => strtoupper($data['factory_id']),
        ]);

        if (! empty($data['password'])) {
            $user->password_hash = Hash::make($data['password']);
        }

        $user->save();

        return $this->adminRedirect(__('triproof.admin.flash.operator_updated'));
    }

    public function destroyOperator(User $user): RedirectResponse
    {
        $this->ensureRole($user, UserRole::FactoryQa);

        $user->delete();

        return $this->adminRedirect(__('triproof.admin.flash.operator_deleted'));
    }

    public function updateSettings(UpdateSystemSettingsRequest $request): RedirectResponse
    {
        $data = $request->validated();

        SystemSetting::setMany([
            'organization_name' => $data['organization_name'],
            'support_email' => $data['support_email'],
            'default_factory_expiry_days' => (string) $data['default_factory_expiry_days'],
            'default_retail_expiry_days' => (string) $data['default_retail_expiry_days'],
            'compliance_notice_en' => $data['compliance_notice_en'] ?? null,
            'compliance_notice_bn' => $data['compliance_notice_bn'] ?? null,
        ]);

        return $this->adminRedirect(__('triproof.admin.flash.settings_updated'));
    }

    private function ensureRole(User $user, UserRole $role): void
    {
        abort_unless($user->role === $role, 404);
    }
}
