<?php

namespace App\trait;

use App\Models\bundle;
use App\Models\subject;

trait student_subjects
{
    public function student_subject($student_id)
    {
        $subjects = subject::whereHas('users', function ($query) use ($student_id) {
            $query->where('users.id', $student_id);
        })
            ->get(); // Get subjects of student
        $bundles = bundle::whereHas('users', function ($query) use ($student_id) {
            $query->where('users.id', $student_id);
        })
            ->with('subjects')
            ->get(); // Get bundles of student
        // return subjects that inside bundles that student buy it
        foreach ($bundles as $bundle) {
            $subjects = $subjects->merge($bundle->subjects);
        }

        return $subjects;
    }
}
