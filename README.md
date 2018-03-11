### Сортировка больших файлов

- задача реализована в виде консольной команды для Yii 2.0
- ограничение памяти принудительно выставлено в 128 Мб

Для сортировки используется следующий алгоритм:
- разбиение большого файла на более мелкие, размер которых
определяется ограничением памяти
- сортировка значений внутри этих файлов стандартными средствами
- последующая сортировка слиянием


Команда генерации тестового файла. В качестве параметра можно передать размер
файла в Мб (по умолчанию 256 Мб)
```
php yii sorter/generate <size>
```

Комадна запуска сортировки. В качестве параметра можно указать имя файла, если
оно отличается от сгенерированного командой generate. Файл ожидается в 
папке runtime проекта
```
php yii sorter <filename>
```

