<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::post('/auth/registration', 'AuthController@register');
Route::any('/auth/login', 'AuthController@login');
Route::any('/auth/userVerification', 'AuthController@userVerification');

Route::middleware('auth:sanctum')->get('/auth/user', function (Request $request) {
    return \App\User::with(['roles', 'groups'])->find($request->user()->id);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::any('/auth/logout', 'AuthController@logout');
});
Route::namespace('Api')->group(function () {
    Route::any('/getCities', 'ApiController@getCities');
    Route::any('/getUserGroups', 'ApiController@getUserGroups');
    Route::any('/uploadCKEditorImage',  'ApiController@uploadCKEditorImage');

    Route::middleware('auth:sanctum')->group(function () {

        Route::any('/getUsers', 'ApiController@getUsers');
        Route::any('/getUser', 'ApiController@getUser');
        Route::any('/deleteUser', 'ApiController@deleteUser');
        Route::any('/resetUserLesson', 'ApiController@resetUserLesson');
        Route::any('/changeUserStatus', 'ApiController@changeUserStatus');

        Route::any('/getUserGroup', 'ApiController@getUserGroup');
        Route::any('/addUserGroup', 'ApiController@addUserGroup');
        Route::any('/postUserGroup', 'ApiController@postUserGroup');
        Route::any('/deleteUserGroup', 'ApiController@deleteUserGroup');

        Route::any('/getCategories', 'ApiController@getCategories');
        Route::any('/addCategory', 'ApiController@addCategory');
        Route::any('/getCategory', 'ApiController@getCategory');
        Route::any('/postCategory', 'ApiController@postCategory');
        Route::any('/deleteCategory', 'ApiController@deleteCategory');

        Route::any('/getCourses', 'ApiController@getCourses');
        Route::any('/addCourse', 'ApiController@addCourse');
        Route::any('/getCourse', 'ApiController@getCourse');
        Route::any('/postCourse', 'ApiController@postCourse');
        Route::any('/deleteCourse', 'ApiController@deleteCourse');

        Route::any('/getLessons', 'ApiController@getLessons');
        Route::any('/addLesson', 'ApiController@addLesson');
        Route::any('/getLesson', 'ApiController@getLesson');
        Route::any('/postLesson', 'ApiController@postLesson');
        Route::any('/deleteLesson', 'ApiController@deleteLesson');

        Route::any('/getDocs', 'ApiController@getDocs');
        Route::any('/uploadDocs', 'ApiController@uploadDocs');
        Route::any('/deleteDocs', 'ApiController@deleteDocs');

        Route::any('/getQuestions', 'ApiController@getQuestions');
        Route::any('/getStudentQuestions', 'ApiController@getStudentQuestions');
        Route::any('/postQuestions', 'ApiController@postQuestions');
        Route::any('/deleteQuestions', 'ApiController@deleteQuestions');
        Route::any('/getStudentAnswers', 'ApiController@getStudentAnswers');

        Route::any('/saveUserAnswers', 'ApiController@saveUserAnswers');
        Route::any('/getQuestionAnswers', 'ApiController@getQuestionAnswers');
        Route::any('/getTestResult', 'ApiController@getTestResult');


        Route::any('/getGroupStatistic', 'ApiController@getGroupStatistic');
        Route::any('/getGroupStatisticXLS', 'ApiController@getGroupStatisticXLS');

    });
});
