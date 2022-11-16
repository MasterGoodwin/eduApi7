<?php

namespace App\Http\Controllers\Api;

use App\Exports\DefaultExport;
use App\Http\Controllers\Controller;
use App\Category;
use App\Course;
use App\Group;
use App\Lesson;
use App\Question;
use App\User;
use App\UserAnswer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image as Img;
use Maatwebsite\Excel\Facades\Excel;
use function now;
use function response;
use function storage_path;

class ApiController extends Controller
{
    public function getCities()
    {
        return response()->json(DB::connection('data')->table('cities')->get());
    }

    public function getUserGroups(Request $request)
    {
        $search = $request['search'];
        $groups = Group::when($search, function($query, $search) {
            $query->where('name', 'like', '%' . $search . '%');
        })
        ->get();
        return response()->json($groups);
    }

    public function getUserGroup(Request $request)
    {
        return response()->json(Group::where('id', $request['id'])->first());
    }

    public function addUserGroup(Request $request)
    {
        Group::insert(['name' => $request['name']]);
    }

    public function postUserGroup(Request $request)
    {
        Group::where('id', $request['id'])->update(['name' => $request['name']]);
        return response()->json('ok');
    }

    public function deleteUserGroup(Request $request)
    {
        DB::transaction(function () use ($request) {
            Group::where('id', $request['id'])->delete();
            DB::table('group_users')->where('group_id', $request['id'])->delete();
        });
        return response()->json('ok');
    }


    public function getUsers(Request $request)
    {
        $users = DB::table('users')->select(['users.id', 'cityId', 'status', 'name', 'email', 'username1c',])
            ->leftJoin('group_users', 'users.id', '=', 'group_users.user_id')->where('status', '!=', 99);
        if (!empty($request['search'])) {
            $users = $users->where('name', 'like', '%' . $request['search'] . '%')
                ->orWhere('name', 'like', '%' . $request['search'] . '%')
                ->orWhere('username1c', 'like', '%' . $request['search'] . '%')
                ->orWhere('email', 'like', '%' . $request['search'] . '%');
        }
        if (($request['allUsers']) === false) {
            $users = $users->where('status', 0);
        }
        if (!empty($request['group'])) {
            $users = $users->where('group_users.group_id', $request['group']);
        }
        if (!empty($request['city'])) {
            $users = $users->where('cityId', $request['city']);
        }
        $users = $users->groupBy('users.id')->get();
        foreach ($users as $user) {
            $user->roles = DB::table('user_roles')->where('user_id', $user->id)->get();
            $user->groups = DB::table('group_users')->where('user_id', $user->id)->get();
        }
        return response()->json($users);
    }

    public function getUser(Request $request)
    {
        $user = User::with(['groups', 'roles'])->find($request->id);
        $userGroups = DB::table('group_users')->where('user_id', $request->id)->pluck('group_id');
        $userCourses = DB::table('course_groups')->whereIn('group_id', $userGroups)->distinct()->pluck('course_id');
        $userLessons = [];
        foreach ($userCourses as $userCourse) {
            $course = DB::table('courses')->where('id', $userCourse)->first();
            $lessons = DB::table('lessons')
                ->select([
                    'lessons.*',
                    'user_attempts.current_count as current_attempt_count',
                    'user_attempts.total_count as total_attempt_count'
                    ])
                ->leftJoin('user_attempts', function($leftJoin) use ($request) {
                    $leftJoin->on('user_attempts.lesson_id', '=', 'lessons.id')
                    ->where('user_attempts.user_id', '=', $request->id);
                })
                ->whereIn('lessons.id',
                    DB::table('course_lessons')
                        ->where('course_id', $userCourse)->pluck('lesson_id')
                )
                ->get();

            foreach ($lessons as $lesson) {
                $lesson->course = $course->name;
                $complete = true;
                $questions = DB::table('questions')->where('lesson_id', $lesson->id)->get();
                if (!count($questions)) $complete = false;
                $right_answers = 0;
                foreach ($questions as $question) {
                    $user_answers = DB::table('user_answers')
                        ->where('user_id', $request->id)
                        ->where('question_id', $question->id)->get();
                    if (!count($user_answers)) $complete = false;
                    $question->user_answers = $user_answers;

                    if ($question->type === 1) {
                        $user_answer = DB::table('user_answers')
                            ->where('user_id', $request->id)
                            ->where('question_id', $question->id)->value('answer_id');
                        if (DB::table('answers')
                                ->where('id', $user_answer)->value('right') === 1) $right_answers++;
                    }

                    if ($question->type === 2) {
                        $right = true;
                        $user_answer = DB::table('user_answers')
                            ->where('user_id', $request->id)
                            ->where('question_id', $question->id)->get();
                        $question_right_answer = DB::table('answers')
                            ->where('question_id', $question->id)
                            ->where('right', 1)->get();
                        if (count($user_answer) !== count($question_right_answer)) $right = false;
                        foreach ($user_answer as $item) {
                            if (DB::table('answers')
                                    ->where('id', $item->answer_id)->first()->right === 0) $right = false;

                        }
                        if ($right) $right_answers++;
                    }

                    if ($question->type === 3) {
                        $user_answer = DB::table('user_answers')
                            ->where('user_id', $request->id)
                            ->where('question_id', $question->id)->value('answer');
                        if (!empty($user_answer)) $right_answers++;
                    }
                }
                if ($complete) {
                    $lesson->right_answers = $right_answers / count($questions) * 100;
                    $lesson->right_answers_count = $right_answers;
                    $lesson->questions_count = count($questions);

                } else {
                    $lesson->right_answers = 0;
                    $lesson->right_answers_count = 0;
                    $lesson->questions_count = count($questions);
                }
                $lesson->complete = $complete;
                $userLessons[] = $lesson;

                if ($course->type === 2) {
                    $lastLessonComplete = false;
                    if ($complete) {
                        $score = $right_answers / count($questions) * 100;
                        $lastLessonComplete = $score >= $course->score;
                    }
                    $lesson->course_attempt = $course->attempt;
                    $lesson->complete_right = $lastLessonComplete;
                }
            }
        }
        $user->lessons = $userLessons;
        return response()->json($user);
    }

    public function deleteUser(Request $request) {
        $isAdmin = DB::table('user_roles')
            ->where('user_id', $request->user()->id)
            ->where('role_id', 9)->exists();

        if ($isAdmin) {
            User::where('id', $request['user_id'])->delete();
        }
    }

    public function resetUserLesson(Request $request)
    {
        $isAdmin = DB::table('user_roles')
            ->where('user_id', $request->user()->id)
            ->where('role_id', 9)->exists();
        $userId = $isAdmin ? $request['user_id'] : $request->user()->id;
        if($userId) {
            $questions = Question::where('lesson_id', $request['lesson_id'])->pluck('id');
            UserAnswer::where('user_id', $userId)->whereIn('question_id', $questions)->delete();
            if($isAdmin && $request['clear_attempt'] === true) {
                DB::table('user_attempts')
                ->where('lesson_id', $request['lesson_id'])
                ->where('user_id', $request['user_id'])
                ->update(['current_count' => 0]);
            }
            return response()->json('ok');
        }
    }

    public function changeUserStatus(Request $request)
    {

        foreach ($request['roles'] as $role) {
            if ($role['status'] === false) {
                DB::table('user_roles')
                    ->where('user_id', $request['user']['id'])
                    ->where('role_id', $role['id'])->delete();
            } else if ($role['status'] === true) {
                DB::table('user_roles')->updateOrInsert(
                    ['user_id' => $request['user']['id'], 'role_id' => $role['id']]
                );
            }
        }
        DB::table('group_users')->where('user_id', $request['user']['id'])->delete();
        foreach ($request['user']['activeGroups'] as $item) {
            if (is_array($item)) {
                DB::table('group_users')->insert([
                    'user_id' => $request['user']['id'],
                    'group_id' => $item['id']
                ]);
            } else {
                $group = DB::table('groups')->insertGetId(['name' => $item]);
                DB::table('group_users')->insert([
                    'user_id' => $request['user']['id'],
                    'group_id' => $group
                ]);
            }

        }
        User::where('id', $request['user']['id'])
            ->update(['status' => $request['user']['status'], 'cityId' => $request['user']['cityId']]);
    }

    public function getCategories(Request $request)
    {
        $search = $request['search'];
        $categories = Category::when($search, function($query, $search) {
            $query->where('name', 'like', '%' . $search . '%');
        })
        ->get();
        return response()->json($categories);
    }

    public function getCategory(Request $request)
    {
        $category = Category::where('id', $request['id'])->first();
        return response()->json($category);
    }

    public function postCategory(Request $request)
    {
        Category::where('id', $request['id'])->update([
            'name' => $request['name'],
        ]);
        return response()->json('ok');
    }

    public function deleteCategory(Request $request)
    {
        Category::where('id', $request['id'])->delete();
        return response()->json('ok');
    }

    public function addCategory(Request $request)
    {
        Category::insert(['name' => $request['name']]);
        return response()->json('ok');
    }

    public function getCourses(Request $request)
    {
        $search = $request['search'];
        $userGroups = DB::table('group_users')->where('user_id', $request->user()->id)->pluck('group_id');
        $userCourses = DB::table('course_groups')->whereIn('group_id', $userGroups)->pluck('course_id');
        $isAdmin = DB::table('user_roles')
            ->where('user_id', $request->user()->id)
            ->where('role_id', 9)->exists();

        if ($isAdmin) {

            $courses = Course::where('category_id', $request['category_id'])
            ->when($search, function($query, $search) {
                $query->where('name', 'like', '%' . $search . '%');
            })->get();
        } else {
            $courses = Course::where('category_id', $request['category_id'])
                ->whereIn('id', $userCourses)
                ->when($search, function($query, $search) {
                    $query->where('name', 'like', '%' . $search . '%');
                })
                ->get();
        }

        foreach ($courses as $course) {
            $questions = DB::table('questions')->select('questions.id')
                ->join('lessons', 'lessons.id', '=', 'questions.lesson_id')
                ->join('course_lessons', 'course_lessons.lesson_id', '=', 'lessons.id')
                ->where('course_lessons.course_id', $course->id)->get();
            $answersCount = 0;
            foreach ($questions as $question) {
                $answers = DB::table('user_answers')
                    ->where('user_id', $request->user()->id)
                    ->where('question_id', $question->id)->get();
                if (count($answers)) $answersCount++;
            }
            $course->complete = count($questions) ? ceil($answersCount / count($questions) * 100) : 0;
        }
        return response()->json($courses);
    }

    public function getCourse(Request $request)
    {
        $course = Course::where('id', $request['id'])->with('groups')->first();
        return response()->json($course);
    }

    public function addCourse(Request $request)
    {
        $course = Course::create([
            'name' => $request['name'],
            'category_id' => $request['category_id'],
            'type' => $request['type'],
            'score' => $request['type'] === 2 ? $request['score'] : 0,
            'attempt' => $request['type'] === 2 ? $request['attempt'] : null,
            'created_at' => Carbon::now(),
            'shuffle' => $request['shuffle']
        ]);

        foreach ($request['groups'] as $item) {
            DB::table('course_groups')->insert([
                'course_id' => $course->id,
                'group_id' => $item['id']
            ]);
        }


        return response()->json('ok');
    }

    public function postCourse(Request $request)
    {
        Course::where('id', $request['id'])->update([
            'name' => $request['name'],
            'category_id' => $request['category_id'],
            'type' => $request['type'],
            'score' => $request['type'] === 2 ? $request['score'] : 0,
            'attempt' => $request['type'] === 2 ? $request['attempt'] : null,
            'shuffle' => $request['shuffle']
        ]);

        DB::table('course_groups')->where('course_id', $request['id'])->delete();
        foreach ($request['groups'] as $item) {
            DB::table('course_groups')->insert([
                'course_id' => $request['id'],
                'group_id' => $item['id']
            ]);
        }

        return response()->json('ok');
    }

    public function deleteCourse(Request $request)
    {
        Course::where('id', $request['id'])->delete();
        return response()->json('ok');
    }


    public function getLessons(Request $request)
    {
        $search = $request['search'];
        $isAdmin = DB::table('user_roles')
            ->where('user_id', $request->user()->id)
            ->where('role_id', 9)->exists();

        $course = DB::table('courses')->where('id', $request['course_id'])->first();

        $lessons = DB::table('lessons')
            ->select([
                'lessons.id as lid',
                'lessons.*',
                'course_lessons.*',
                'user_attempts.current_count as attempt_count'
            ])
            ->leftJoin('course_lessons', 'course_lessons.lesson_id', '=', 'lessons.id')
            ->leftJoin('user_attempts', function($leftJoin) use ($request) {
                $leftJoin->on('user_attempts.lesson_id', '=', 'lessons.id')
                ->where('user_attempts.user_id', '=', $request->user()->id);
            })
            ->where('course_lessons.course_id', $request['course_id'])
            ->when($search, function($query, $search) {
                $query->where('name', 'like', '%' . $search . '%');
            })
            ->get();

        $resultLessons = [];
        foreach ($lessons as $key => $lesson) {
            if ($lesson->start && Carbon::createFromFormat('Y-m-d H:i:s', $lesson->start)->isFuture()) continue;
            if ($lesson->end && Carbon::createFromFormat('Y-m-d H:i:s', $lesson->end)->isPast()) continue;
            $complete = true;
            $questions = DB::table('questions')->where('lesson_id', $lesson->lid)->get();
            if (!count($questions)) $complete = false;
            $right_answers = 0;
            foreach ($questions as $question) {
                $user_answers = DB::table('user_answers')
                    ->where('user_id', $request->user()->id)
                    ->where('question_id', $question->id)->get();
                if (!count($user_answers)) $complete = false;
                $question->user_answers = $user_answers;

                if ($question->type === 1) {
                    $user_answer = DB::table('user_answers')
                        ->where('user_id', $request->user()->id)
                        ->where('question_id', $question->id)->value('answer_id');
                    if (DB::table('answers')
                            ->where('id', $user_answer)->value('right') === 1) $right_answers++;
                }

                if ($question->type === 2) {
                    $right = true;
                    $user_answer = DB::table('user_answers')
                        ->where('user_id', $request->user()->id)
                        ->where('question_id', $question->id)->get();
                    $question_right_answer = DB::table('answers')
                        ->where('question_id', $question->id)
                        ->where('right', 1)->get();
                    if (count($user_answer) !== count($question_right_answer)) $right = false;
                    foreach ($user_answer as $item) {
                        if (DB::table('answers')
                                ->where('id', $item->answer_id)->first()->right === 0) $right = false;

                    }
                    if ($right) $right_answers++;
                }

                if ($question->type === 3) {
                    $user_answer = DB::table('user_answers')
                        ->where('user_id', $request->user()->id)
                        ->where('question_id', $question->id)->value('answer');
                    if (!empty($user_answer)) $right_answers++;
                }
            }
            $lesson->complete = $complete;

            if ($course->type === 1) {
                $resultLessons[] = $lesson;
            } else {
                if ($key === 0) {
                    $resultLessons[] = $lesson;
                } else {
                    if (isset($lastLessonComplete) && $lastLessonComplete) {
                        $resultLessons[] = $lesson;
                    } else {
                        break;
                    }
                }
                if ($complete) {
                    $score = $right_answers / count($questions) * 100;
                    $lastLessonComplete = $score >= $course->score;
                } else {
                    $lastLessonComplete = false;
                }
                $lesson->complete_right = $lastLessonComplete;
            }

        }

//        $lessons = DB::table('lessons')
//            ->leftJoin('course_lessons', 'course_lessons.lesson_id', '=', 'lessons.id')
//            ->where('course_lessons.course_id', $request['course_id'])
//            ->get();
//
//        foreach ($lessons as $lesson) {
//            $complete = true;
//            $questions = DB::table('questions')->where('lesson_id', $lesson->id)->get();
//            if (!count($questions)) $complete = false;
//            foreach ($questions as $question) {
//                $answers = DB::table('user_answers')
//                    ->where('user_id', $request->user()->id)
//                    ->where('question_id', $question->id)->get();
//                if (!count($answers)) $complete = false;
//            }
//            $lesson->complete = $complete;
//        }
        return response()->json([
            'lessons' => $isAdmin ? $lessons : $resultLessons,
            'lessons_count' => count($lessons),
            'category_id' => $course->category_id,
            'course_attempt_count' => $course->attempt,
        ]);
//        return response()->json(['lessons' => $lessons, 'category_id' => $course->category_id]);
    }

    public function getLesson(Request $request)
    {
        $lesson = Lesson::where('id', $request['id'])->with('groups')->first();
        /*   if (!empty($lesson->start)) $lesson->start = Carbon::createFromFormat('Y-m-d H:i:s', $lesson->start)->format('d.m.Y H:i');
          if (!empty($lesson->end)) $lesson->end = Carbon::createFromFormat('Y-m-d H:i:s', $lesson->end)->format('d.m.Y H:i'); */

        $complete = true;
        $questions = DB::table('questions')->where('lesson_id', $lesson->id)->get();
        if (!count($questions)) $complete = false;
        foreach ($questions as $question) {
            $answers = DB::table('user_answers')
                ->where('user_id', $request->user()->id)
                ->where('question_id', $question->id)->get();
            if (!count($answers)) $complete = false;
        }
        $lesson->complete = $complete;

        return response()->json([
            'lesson' => $lesson,
            'course_id' => DB::table('course_lessons')->where('lesson_id', $lesson->id)->value('course_id')
        ]);
    }

    public function addLesson(Request $request)
    {
        DB::transaction(function () use ($request) {
            $lesson = $request['lesson'];
            $lessonId = Lesson::insertGetId([
                'name' => $lesson['name'],
                'content' => $lesson['content'],
                'comments' => $lesson['comments'],
                'result_type' => $lesson['result_type'],
//                'type' => $lesson['type'],
                'start' => $lesson['start'],
                'end' => $lesson['end'] !== null ? Carbon::createFromFormat('Y-m-d H:i:s', Carbon::parse($lesson['end']))->addDays(1)->subSeconds(1)->format('Y-m-d H:i:s') : null,
                'created_at' => Carbon::now(),
            ]);

            DB::table('course_lessons')->insert([
                'course_id' => $request['course_id'],
                'lesson_id' => $lessonId
            ]);


//            foreach ($lesson['groups'] as $item) {
//                DB::table('lesson_groups')->insert([
//                    'lesson_id' => $lessonId,
//                    'group_id' => $item['id']
//                ]);
//            }
        });
        return response()->json('ok');
    }

    public function postLesson(Request $request)
    {
        $lesson = $request['lesson'];
        Lesson::where('id', $lesson['id'])->update([
            'name' => $lesson['name'],
            'content' => $lesson['content'],
            'comments' => $lesson['comments'],
            'result_type' => $lesson['result_type'],
//            'type' => $lesson['type'],
            'start' => $lesson['start'],
            'end' => $lesson['end'] !== null ? Carbon::createFromFormat('Y-m-d H:i:s', Carbon::parse($lesson['end']))->addDays(1)->subSeconds(1)->format('Y-m-d H:i:s') : null,
        ]);
//        DB::table('lesson_groups')->where('lesson_id', $lesson['id'])->delete();
//        foreach ($lesson['groups'] as $item) {
//            DB::table('lesson_groups')->insert([
//                'lesson_id' => $lesson['id'],
//                'group_id' => $item['id']
//            ]);
//        }

        return response()->json('ok');
    }

    public function deleteLesson(Request $request)
    {
        DB::transaction(function () use ($request) {
            Lesson::where('id', $request['id'])->delete();
            DB::table('course_lessons')->where('lesson_id', $request['id'])->delete();
        });
        return response()->json('ok');
    }


    public function getQuestions(Request $request)
    {
        $questions = Question::where('lesson_id', $request['lesson_id'])->get();
        foreach ($questions as $question) {
            $question->delete = false;
            $question->answers = DB::table('answers')->where('question_id', $question['id'])->get();
            foreach ($question->answers as $answer) {
                $answer->delete = false;
            }
        }
        return response()->json([
            'questions' => $questions,
            'course_id' => DB::table('course_lessons')->where('lesson_id', $request['lesson_id'])->value('course_id')
        ]);
    }

    public function getStudentQuestions(Request $request)
    {
        $complete = true;
        $questions = Question::where('lesson_id', $request['lesson_id'])->get();
        $shuffle = DB::table('course_lessons')
            ->leftJoin('courses', 'courses.id', 'course_lessons.course_id')
            ->where('lesson_id', $request['lesson_id'])
            ->value('shuffle');
        if (!count($questions)) $complete = false;
        foreach ($questions as $question) {
            if ($question->type === 1) {
                $question->user_answer = null;
            } else if ($question->type === 2) {
                $question->user_answer = [];
            } else if ($question->type === 3) {
                $question->user_answer = "";
            }
            $question->answers = DB::table('answers')->select(['id', 'question_id', 'order', 'answer', 'comment'])
                ->where('question_id', $question['id'])->get();

            $answers = DB::table('user_answers')
                ->where('user_id', $request->user()->id)
                ->where('question_id', $question->id)->get();
            if (!count($answers)) $complete = false;

        }

        return response()->json(['questions' => $questions, 'complete' => $complete, 'shuffle' => $shuffle]);
    }

    public function getStudentAnswers(Request $request)
    {
        $complete = true;
        $questions = Question::where('lesson_id', $request['lesson_id'])->get();
        if (!count($questions)) $complete = false;
        foreach ($questions as $question) {
            $question->answers = DB::table('answers')
                ->where('question_id', $question['id'])->get();

            $user_answers = DB::table('user_answers')
                ->where('user_id', $request['user_id'])
                ->where('question_id', $question->id)->get();
            if (count($user_answers)) {
                if ($question->type === 1) {
                    $question->user_answer = DB::table('user_answers')
                    ->where('user_id', $request['user_id'])
                    ->where('question_id', $question->id)->value('answer_id');
                } else if ($question->type === 2) {
                    $question->user_answer = DB::table('user_answers')
                    ->where('user_id', $request['user_id'])
                    ->where('question_id', $question->id)->pluck('answer_id');
                } else if ($question->type === 3) {
                    $question->user_answer = DB::table('user_answers')
                    ->where('user_id', $request['user_id'])
                    ->where('question_id', $question->id)->value('answer');
                }
            } else {
                $complete = false;
            }
        }

        return response()->json(['questions' => $questions, 'complete' => $complete]);
    }

    public function getQuestionAnswers(Request $request)
    {
        $questions = Question::where('lesson_id', $request['lesson_id'])->get();
        foreach ($questions as $question) {
            if ($question->type === 1) {
                $question->user_answer = null;
            } else if ($question->type === 2) {
                $question->user_answer = [];
            } else if ($question->type === 3) {
                $question->user_answer = "";
            }
            $question->answers = DB::table('answers')->where('question_id', $question['id'])->get();
        }

        return response()->json($questions);
    }

    public function getTestResult(Request $request)
    {
        $requestUserId = $request['user_id'] ? $request['user_id'] : $request->user()->id;
        $user = User::with(['groups', 'roles'])->find($requestUserId);

        $lesson = DB::table('lessons')
            ->where('id', $request->lesson_id)
            ->first();


        $complete = true;
        $questions = DB::table('questions')->where('lesson_id', $lesson->id)->get();
        if (!count($questions)) $complete = false;
        $right_answers = 0;
        foreach ($questions as $question) {
            $user_answers = DB::table('user_answers')
                ->where('user_id', $user->id)
                ->where('question_id', $question->id)->get();
            if (!count($user_answers)) $complete = false;
            $question->user_answers = $user_answers;

            if ($question->type === 1) {
                $user_answer = DB::table('user_answers')
                    ->where('user_id', $user->id)
                    ->where('question_id', $question->id)->value('answer_id');
                if (DB::table('answers')
                        ->where('id', $user_answer)->value('right') === 1) $right_answers++;
            }

            if ($question->type === 2) {
                $right = true;
                $user_answer = DB::table('user_answers')
                    ->where('user_id', $user->id)
                    ->where('question_id', $question->id)->get();
                $question_right_answer = DB::table('answers')
                    ->where('question_id', $question->id)
                    ->where('right', 1)->get();
                if (count($user_answer) !== count($question_right_answer)) $right = false;
                foreach ($user_answer as $item) {
                    if (DB::table('answers')
                            ->where('id', $item->answer_id)->first()->right === 0) $right = false;

                }
                if ($right) $right_answers++;
            }

            if ($question->type === 3) {
                $user_answer = DB::table('user_answers')
                    ->where('user_id', $user->id)
                    ->where('question_id', $question->id)->value('answer');
                if (!empty($user_answer)) $right_answers++;
            }
        }
        if ($complete) {
            $score = $right_answers / count($questions) * 100;
            $questions_count = count($questions);

        } else {
            $score = 0;
            $questions_count = count($questions);
        }


        return response()->json(['score' => $score, 'right_answers' => $right_answers, 'questions_count' => $questions_count]);

    }

    public function postQuestions(Request $request)
    {
        foreach ($request['questions'] as $i => $question) {
            if (isset ($question['id'])) {
                if ($question['delete']) {
                    DB::table('questions')->where('id', $question['id'])->delete();
                    DB::table('answers')->where('question_id', $question['id'])->delete();
                } else {
                    $questionId = $question['id'];
                    DB::table('questions')->where('id', $questionId)->update([
                        'type' => $question['type'],
                        'order' => $i,
                        'question' => $question['question'],
                        'updated_at' => Carbon::now(),
                    ]);
                }
            } else {
                if (!$question['delete']) {
                    $questionId = DB::table('questions')->insertGetId([
                        'lesson_id' => $request['lesson_id'],
                        'type' => $question['type'],
                        'order' => $i,
                        'question' => $question['question'],
                        'created_at' => Carbon::now(),
                    ]);
                }
            }
            if (isset($questionId)) {
                foreach ($question['answers'] as $k => $answer) {
                    if (isset ($answer['id']) && ($answer['delete']) === true) {
                        DB::table('answers')->where('id', $answer['id'])->delete();
                    }
                    if (isset ($answer['id'])) {
                        DB::table('answers')->where('id', $answer['id'])->update([
                            'order' => $k,
                            'answer' => $answer['answer'],
                            'comment' => $answer['comment'],
                            'right' => $answer['right'],
                        ]);

                    } elseif(!$answer['delete'] && !$question['delete']) {
                        DB::table('answers')->insert([
                            'question_id' => $questionId,
                            'order' => $k,
                            'answer' => $answer['answer'],
                            'comment' => $answer['comment'],
                            'right' => $answer['right'],
                        ]);
                    }
                }
            }
        }
        return response()->json('ok');
    }

    public function deleteQuestions(Request $request)
    {
        return response()->json('ok');
    }

    public function saveUserAnswers(Request $request)
    {
        // TODO Сохранение ответов пользователя
        DB::table('user_answers')
            ->join('questions', 'user_answers.question_id', '=', 'questions.id')
            ->where('questions.lesson_id', $request->lesson_id)
            ->where('user_answers.user_id', $request->user()->id)->delete();

        foreach ($request->questions as $question) {
            if ($question['type'] === 1) {
                DB::table('user_answers')->insert([
                    'user_id' => $request->user()->id,
                    'question_id' => $question['id'],
                    'answer_id' => $question['user_answer'],
                    'right' => DB::table('answers')
                        ->where('question_id', $question['id'])
                        ->where('id', $question['user_answer'])->first()->right,
                    'created_at' => now()
                ]);
            }
            if ($question['type'] === 2) {
                foreach ($question['user_answer'] as $answer) {
                    DB::table('user_answers')->insert([
                        'user_id' => $request->user()->id,
                        'question_id' => $question['id'],
                        'answer_id' => $answer,
                        'right' => DB::table('answers')
                            ->where('question_id', $question['id'])
                            ->where('id', $answer)->first()->right,
                        'created_at' => now()
                    ]);
                }
            }
            if ($question['type'] === 3) {
                DB::table('user_answers')->insert([
                    'user_id' => $request->user()->id,
                    'question_id' => $question['id'],
                    'answer' => $question['user_answer'],
                    'created_at' => now()
                ]);
            }

        }

        $lessonAttempt = DB::table('user_attempts')
        ->where('user_id', $request->user()->id)
        ->where('lesson_id', $request->lesson_id)->first();

        if(isset($lessonAttempt)) {
            DB::table('user_attempts')
            ->where('user_id', $request->user()->id)
            ->where('lesson_id', $request->lesson_id)
            ->update([
                'current_count' => ++$lessonAttempt->current_count,
                'total_count' => ++$lessonAttempt->total_count,
                'updated_at' => Carbon::now()
            ]);
        } else {
            DB::table('user_attempts')
            ->insert([
                'user_id' => $request->user()->id,
                'lesson_id' => $request->lesson_id,
                'current_count' => 1,
                'total_count' => 1,
                'updated_at' => Carbon::now(),
                'created_at' => Carbon::now(),
            ]);
        }

        $complete = true;
        $questions = DB::table('questions')->where('lesson_id', $request->lesson_id)->get();
        if (!count($questions)) $complete = false;
        $right_answers = 0;
        foreach ($questions as $question) {
            $user_answers = DB::table('user_answers')
                ->where('user_id', $request->user()->id)
                ->where('question_id', $question->id)->get();
            if (!count($user_answers)) $complete = false;
            $question->user_answers = $user_answers;

            if ($question->type === 1) {
                $user_answer = DB::table('user_answers')
                    ->where('user_id', $request->user()->id)
                    ->where('question_id', $question->id)->value('answer_id');
                if (DB::table('answers')
                        ->where('id', $user_answer)->value('right') === 1) $right_answers++;
            }

            if ($question->type === 2) {
                $right = true;
                $user_answer = DB::table('user_answers')
                    ->where('user_id', $request->user()->id)
                    ->where('question_id', $question->id)->get();
                $question_right_answer = DB::table('answers')
                    ->where('question_id', $question->id)
                    ->where('right', 1)->get();
                if (count($user_answer) !== count($question_right_answer)) $right = false;
                foreach ($user_answer as $item) {
                    if (DB::table('answers')
                            ->where('id', $item->answer_id)->first()->right === 0) $right = false;

                }
                if ($right) $right_answers++;
            }

            if ($question->type === 3) {
                $user_answer = DB::table('user_answers')
                    ->where('user_id', $request->user()->id)
                    ->where('question_id', $question->id)->value('answer');
                if (!empty($user_answer)) $right_answers++;
            }
        }
        if ($complete) {
            $score = $right_answers / count($questions) * 100;
            $right = $right_answers;
            $total = count($questions);
            $completeRight = null;
            $course = DB::table('courses')->where('id',
                DB::table('course_lessons')->where('lesson_id', $request->lesson_id)->value('course_id')
            )->first();
            if ($course->type === 2) {
                $completeRight = $score >= $course->score;
            }
            DB::table('user_stat')->updateOrInsert([
                'user_id' => $request->user()->id, 'lesson_id' => $request->lesson_id
            ], [
                'score' => $score, 'right' => $right, 'total' => $total, 'complete' => $completeRight,
                'updated_at' => Carbon::now(),
                'created_at' => Carbon::now(),
            ]);
        }


        return response()->json($request->user()->id);
    }

    public static function uploadCKEditorImage(Request $request)
    {
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
            $hash = md5($file);
            $fileName = $hash . '.' . $ext;
            $img = Img::make($file->getRealPath());

            $path = storage_path('app/public/ckeditor/');
            if (!file_exists($path)) {
                mkdir($path, 0777, true);
            }

            $img->save(storage_path('app/public/ckeditor/' . $fileName), 100);

            return [
                'file' => $fileName,
                'result' => true,
            ];
        } else {
            return ['hint' => 'Недопустимый формат файла.', 'result' => false,];

        }
    }

    public function uploadDocs(Request $request)
    {
        $lessonId = $request->lesson_id;
        $files = $request->file('files');
        foreach ($files as $file) {
            $ext = strtolower($file->getClientOriginalExtension());
            $originalName = strtolower($file->getClientOriginalName());
            $hash = md5($file);
            $fileName = $hash . '.' . $ext;
            $path = storage_path('app/public/docs/');
            if (!file_exists($path)) {
                mkdir($path, 0777, true);
            }
            $destinationPath = storage_path('/app/public/docs/');
            $file->move($destinationPath, $fileName);

            DB::table('docs')->insert([
                'lesson_id' => $lessonId,
                'name' => $originalName,
                'src_name' => $fileName,
            ]);
        }

        return response()->json(['result' => true]);
    }

    public function getDocs(Request $request)
    {
        $docs = DB::table('docs')->where('lesson_id', $request['lesson_id'])->get();
        return response()->json($docs);
    }

    public function deleteDocs(Request $request)
    {
        if (isset($request['status']) && $request['status'] === 'all') {
            $src_names = DB::table('docs')->where('lesson_id', $request['lesson_id'])->pluck('src_name');
            DB::table('docs')->where('lesson_id', $request['lesson_id'])->delete();
            foreach ($src_names as $file) {
                $path = 'public/docs/' . $file;
                if (Storage::exists($path)) {
                    Storage::delete($path);
                }
            }
        } else {
            $src_name = DB::table('docs')->where('id', $request['doc_id'])->first()->src_name;
            DB::table('docs')->where('id', $request['doc_id'])->delete();
            $path = 'public/docs/' . $src_name;
            if (Storage::exists($path)) {
                Storage::delete($path);
            }
        }
        return response()->json('ok');
    }


    public function getGroupStatistic(Request $request)
    {
        $users = User::whereIn('id', DB::table('group_users')
            ->where('group_id', $request->id)->pluck('user_id'))->get();

        $groupLessons = [];
        $stat = [];
        $scores = [];

        foreach ($users as $user) {
            $userCourses = DB::table('course_groups')->where('group_id', $request->id)->distinct()->pluck('course_id');

            foreach ($userCourses as $userCourse) {
                $course = DB::table('courses')->where('id', $userCourse)->first();
                if (!$course) continue;
                $category = DB::table('categories')->where('id', $course->category_id)->first();
                if (!$category) continue;
                $lessons = DB::table('lessons')
                    ->whereIn('lessons.id',
                        DB::table('course_lessons')
                            ->where('course_id', $userCourse)->pluck('lesson_id')
                    )
                    ->get();

                foreach ($lessons as $lesson) {
                    $user_stat = DB::table('user_stat')->where('user_id', $user->id)->where('lesson_id', $lesson->id)->first();
                    $userLessonStat = [
                        'user' => $user,
                        'complete' => (bool)$user_stat,
                        'pass' => $user_stat && $user_stat->complete,
                        'date' => $user_stat ? Carbon::createFromFormat('Y-m-d H:i:s', $user_stat->created_at)->format('d.m.Y') : null,
                        'score' => $user_stat ? $user_stat->score : 0,
                        'try' => null
                    ];
                    $lesson->course = $course->name;
                    $gKey = array_search($lesson->id, array_column($groupLessons, 'id'));
                    if ($gKey === false) {
                        $groupLessons[] = $lesson;
                        $gKey = count($groupLessons) - 1;
                    }

                    if (!array_key_exists($lesson->id, $scores)) $scores[$lesson->id] = [];

                    $sKey = array_search($lesson->id, array_column($stat, 'id'));
                    if ($sKey === false) {
                        $stat[] = [
                            'id' => $lesson->id,
                            'complete' => 0,
                            'pass' => 0,
                            'score' => 0,
                            'users' => []
                        ];
                        $sKey = count($stat) - 1;
                    }

                    if ($user_stat) {
                        $stat[$sKey]['complete']++;

                        if ($course->type === 2) {
                            $scores[$lesson->id][] = $user_stat->score;
                            if ($user_stat->complete) {
                                $stat[$sKey]['pass']++;
                            }

                        }
                    }
                    $stat[$sKey]['users'][] = $userLessonStat;
                }
            }
        }
        foreach ($scores as $lessonId => $score) {
            $sKey = array_search($lessonId, array_column($stat, 'id'));
            if (count($score)) {
                $stat[$sKey]['score'] = round(array_sum($score) / count($score));
            } else {
                $stat[$sKey]['score'] = 0;
            }
        }

        return response()->json(['lessons' => (array)$groupLessons, 'stat' => (array)$stat, 'usersCount' => count($users)]);
    }

    public function getGroupStatisticXLS(Request $request, Excel $excel)
    {
        $preHeader = ['Кол-во прошло', 'Успешно', 'Ср. балл'];
        $header = ['Имя', 'email', 'Город', 'Дата', 'Пройден', 'Успешно', 'Оценка'];
        $items = [];

        foreach ($request->stat['users'] as $item) {
            $items[] = [
                $item['user']['username1c'],
                $item['user']['email'],
                DB::connection('data')->table('cities')->where('id', $item['user']['cityId'])->value('name'),
                $item['date'],
                $item['complete'] ? 'Да' : 'Нет',
                $item['pass'] ? 'Да' : 'Нет',
                $item['score'],
            ];
        }

        $export = new DefaultExport([
            [$request->title],
            $preHeader,
            [$request->stat['complete'], $request->stat['pass'], $request->stat['score']],
            $header,
            $items
        ]);

        return $excel::download($export, 'stat.xlsx');
    }

    public function addUser(Request $request)
    {
        $pass = rand(0, 9) . rand(0, 9) . rand(0, 9) . rand(0, 9) . rand(0, 9) . rand(0, 9);
        while (DB::table('users')->where('password', $pass)->exists()) {
            $pass = rand(0, 9) . rand(0, 9) . rand(0, 9) . rand(0, 9) . rand(0, 9) . rand(0, 9);
        }
        DB::table('users')->updateOrInsert(['cid' => $request->id], [
            'name' => $request->name,
            'welcome_test_id' => $request->testId,
        ]);
        return response()->json($pass);
    }

}
