
# -Инструкция
Комментарий из таймлайна в поля
Приложение которое берет инфу блока комментарий из таймлайна и закидывает их в поля карточки сделки
Берет он информацию только в таком формате:

![image](https://github.com/user-attachments/assets/38f6f9c6-6a3a-4851-aeb6-a2dc96fa0e93)


Инструкция:

* Устанавливаем приложение в Битрикс24
* Заходим в Бизнес-процессы --> Процессы в CRM --> Шаблоны бизнес-процессов для "Сделок" --> + Добавить шаблон --> Действия приложений, перенесите появившееся действие в начало структуры
* После открытия НАстройки параметров действия --> Поле ID сделки: {{ID элемента CRM}} --> Сохранить
* Перейдите в сделку и после заполнения приложением таймлайна комментария нажать на Бизнес-процесс запустить ваш Бизнес-процесс
* Все готово.
В коде есть код поля, его нужно поменять
