<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Registration;

class BackfillRegistrationFormData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backfill:registrations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill missing form_data JSON for registrations using registration/student data';

    public function handle()
    {
        $this->info('Starting backfill of registrations.form_data...');
        $count = 0;

        Registration::with('student')->whereNull('form_data')->chunk(100, function ($rows) use (&$count) {
            foreach ($rows as $registration) {
                $student = $registration->student;

                $formData = [
                    'mssv' => $registration->student?->student_code ?? $student?->student_code ?? null,
                    'fullName' => $student?->full_name ?? $registration->full_name ?? null,
                    'birthDate' => $registration->date_of_birth ?? $student?->date_of_birth ?? null,
                    'gender' => $registration->gender ?? $student?->gender ?? null,
                    'class' => $registration->class ?? $student?->class_name ?? null,
                    'department' => $registration->department ?? $student?->faculty ?? null,
                    'nationality' => $registration->nationality ?? $student?->nationality ?? null,
                    'ethnicity' => $registration->ethnicity ?? $student?->ethnicity ?? null,
                    'religion' => $registration->religion ?? $student?->religion ?? null,
                    'phone' => $registration->phone ?? $student?->phone ?? null,
                    'cccd' => $registration->cccd ?? $student?->cccd ?? null,
                    'cccdIssueDate' => $registration->cccd_issued_date ?? null,
                    'cccdIssuePlace' => $registration->cccd_issued_place ?? null,
                    'address' => $registration->parent_address ?? $student?->permanent_address ?? null,
                    'father_name' => $registration->father_name ?? null,
                    'father_phone' => $registration->father_phone ?? null,
                    'father_job' => $registration->father_job ?? null,
                    'mother_name' => $registration->mother_name ?? null,
                    'mother_phone' => $registration->mother_phone ?? null,
                    'mother_job' => $registration->mother_job ?? null,
                    'familyContactAddress' => $registration->parent_address ?? null,
                ];

                $registration->form_data = json_encode($formData);
                $registration->save();
                $count++;
            }
        });

        $this->info("Backfilled $count registrations.");
        return 0;
    }
}
