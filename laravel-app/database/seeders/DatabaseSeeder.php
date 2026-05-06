<?php

namespace Database\Seeders;

use App\Models\ApprovalRequest;
use App\Models\Booking;
use App\Models\Contract;
use App\Models\Facility;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\MaintenanceRequest;
use App\Models\Meeting;
use App\Models\Owner;
use App\Models\Resolution;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(PermissionSeeder::class);

        $superAdminRole = \App\Models\Role::where('key', 'super_admin')->first();

        $user = User::query()->updateOrCreate(['email' => 'admin@edarat365.com'], [
            'name' => 'Edarat Admin',
            'phone' => '0590592324',
            'role' => 'admin',
            'role_id' => $superAdminRole?->id,
            'is_active' => true,
            'avatar_url' => 'https://i.pravatar.cc/160?img=12',
            'password' => Hash::make('password123'),
        ]);

        $owner = Owner::query()->create([
            'user_id' => $user->id,
            'national_id' => '1000000001',
            'full_name' => 'مالك تجريبي',
            'phone' => '0500000000',
            'email' => 'owner@edarat365.com',
        ]);

        $unit = Unit::query()->create([
            'unit_number' => 'A-101',
            'building_name' => 'برج الإدارة',
            'ownership_ratio' => 100,
            'owner_id' => $owner->id,
        ]);

        Invoice::query()->create([
            'owner_id' => $owner->id,
            'unit_id' => $unit->id,
            'amount' => 1250.50,
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => 'pending',
        ]);

        $facility = Facility::query()->create([
            'name' => 'قاعة الاجتماعات',
            'description' => 'قاعة متعددة الاستخدام',
            'is_active' => true,
        ]);

        Booking::query()->create([
            'facility_id' => $facility->id,
            'owner_id' => $owner->id,
            'starts_at' => now()->addDays(1),
            'ends_at' => now()->addDays(1)->addHours(2),
            'status' => 'approved',
        ]);

        Contract::query()->create([
            'owner_id' => $owner->id,
            'unit_id' => $unit->id,
            'tenant_name' => 'مستأجر تجريبي',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);

        MaintenanceRequest::query()->create([
            'owner_id' => $owner->id,
            'unit_id' => $unit->id,
            'title' => 'صيانة التكييف',
            'description' => 'التبريد ضعيف',
            'priority' => 'high',
            'status' => 'open',
        ]);

        $meeting = Meeting::query()->create([
            'title' => 'اجتماع ربع سنوي',
            'scheduled_at' => now()->addDays(4),
            'type' => 'general',
            'agenda' => 'مراجعة الميزانية واعتماد الصيانة',
        ]);

        Resolution::query()->create([
            'meeting_id' => $meeting->id,
            'title' => 'قرار تحسين الخدمات',
            'description' => 'اعتماد ميزانية تحسين الخدمات',
            'yes_votes' => 12,
            'no_votes' => 2,
            'abstain_votes' => 1,
        ]);

        LegalCase::query()->create([
            'case_number' => 'LC-2026-001',
            'title' => 'مطالبة تعويض',
            'status' => 'open',
            'hearing_date' => now()->addMonths(1)->toDateString(),
        ]);

        ApprovalRequest::query()->create([
            'request_type' => 'expense',
            'status' => 'pending',
            'requested_by' => $user->id,
            'notes' => 'موافقة على عقد صيانة المصاعد',
        ]);
    }
}
