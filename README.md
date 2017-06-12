Yii2 Query Template
===================
Embed your data from database to your content in a flexible way

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist vanquyet/yii2-query-template "*"
```

or add

```
"vanquyet/yii2-query-template": "*"
```

to the require section of your `composer.json` file.


Usage
-----

Once the extension is installed, simply use it in your code by  :

```html
<!-- Execute function and write the result as a string -->
{{% getStudentName(123) %}}

<!-- Embed function in the string -->
"My name is [[% getStudentName(123) %]] "

<!-- Write variable -->
{{* my_name *}}

<!-- Embed variable in the string -->
"My name is [[* my_name *]] "

<!-- Object method -->
{{% findStudent(123).#getInfo("name") %}}

<!-- Object method embedded in the string -->
"Student name: [[% findStudent(123).@getInfo(\"name\") %]] "
{{% findStudent(123).#imgTag({"alt": "Student name: [[% this.@getInfo(\"name\") %]]"}) %}}

<!-- Variable assignment -->
((` my_name : getStudentName(123) `))
((` country : "Vietnam" `))
((` year : 1993 `))
```