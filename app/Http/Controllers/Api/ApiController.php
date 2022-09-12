<?php

namespace App\Http\Controllers\Api;

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
use function now;
use function response;
use function storage_path;

class ApiController extends Controller
{
    public function getCities()
    {
        return response()->json(DB::connection('data')->table('cities')->get());
    }

    public function getUserGroups()
    {
        return response()->json(Group::get());
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
            ->leftJoin('group_users', 'users.id', '=', 'group_users.user_id');
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
            }
        }
        $user->lessons = $userLessons;
        return response()->json($user);
    }

    public function resetUserLesson(Request $request)
    {
        $questions = Question::where('lesson_id', $request['lesson_id'])->pluck('id');
        UserAnswer::where('user_id', $request['user_id'])->whereIn('question_id', $questions)->delete();
        return response()->json('ok');
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
        $categories = Category::get();
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
        $userGroups = DB::table('group_users')->where('user_id', $request->user()->id)->pluck('group_id');
        $userCourses = DB::table('course_groups')->whereIn('group_id', $userGroups)->pluck('course_id');
        $isAdmin = DB::table('user_roles')
            ->where('user_id', $request->user()->id)
            ->where('role_id', 9)->exists();

        if ($isAdmin) {
            $courses = Course::where('category_id', $request['category_id'])->get();
        } else {
            $courses = Course::where('category_id', $request['category_id'])
                ->whereIn('id', $userCourses)
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
            'created_at' => Carbon::now(),
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
        $isAdmin = DB::table('user_roles')
            ->where('user_id', $request->user()->id)
            ->where('role_id', 9)->exists();

        $course = DB::table('courses')->where('id', $request['course_id'])->first();

        $lessons = DB::table('lessons')
            ->leftJoin('course_lessons', 'course_lessons.lesson_id', '=', 'lessons.id')
            ->where('course_lessons.course_id', $request['course_id'])
            ->get();

        $resultLessons = [];

        foreach ($lessons as $key => $lesson) {
            $complete = true;
            $questions = DB::table('questions')->where('lesson_id', $lesson->id)->get();
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
        return response()->json(['lessons' => $isAdmin ? $lessons : $resultLessons, 'category_id' => $course->category_id]);
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
                'end' => $lesson['end'],
                /* 'start' => !empty($lesson['start']) ? Carbon::createFromFormat('d.m.Y H:i', $lesson['start']) : null,
                'end' => !empty($lesson['end']) ? Carbon::createFromFormat('d.m.Y H:i', $lesson['end']) : null, */
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
            'end' => $lesson['end'],
            //'start' => !empty($lesson['start']) ? Carbon::createFromFormat('d.m.Y', $lesson['start']) : null,
            //'end' => !empty($lesson['end']) ? Carbon::createFromFormat('d.m.Y', $lesson['end']) : null
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
        $questions = Question::where('lesson_id', $request['lesson_id'])->get();
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
        }

        return response()->json($questions);
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
        $user = User::with(['groups', 'roles'])->find($request->user()->id);

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
                    if (isset ($answer['id'])) {
                        DB::table('answers')->where('id', $answer['id'])->update([
                            'order' => $k,
                            'answer' => $answer['answer'],
                            'comment' => $answer['comment'],
                            'right' => $answer['right'],
                        ]);

                    } else {
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
            if (in_array($ext, ['xls', 'xlsx', 'pdf'])) {
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
            } else {
                return ['hint' => 'Недопустимый формат файла.', 'result' => false,];
            }
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
}
