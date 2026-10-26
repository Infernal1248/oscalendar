<?php
namespace Tests\Unit;

use App\Services\FlightReportDelayReasons;
use PHPUnit\Framework\TestCase;

class FlightReportDelayReasonsTest extends TestCase
{
    public function test_reads_multiple_reasons_and_stops_before_next_section(): void
    {
        $data = ['sheets'=>[['cells'=>[
            'C20'=>['value'=>'Причины задержки'],
            'C21'=>['value'=>'Код'], 'E21'=>['value'=>'Наименование'],
            'C22'=>['value'=>'56 EC'], 'E22'=>['value'=>'Подготовка грузовой докум.'],
            'C23'=>['value'=>'75 WI'], 'E23'=>['value'=>'Противообледенит. обр-ка ВС'],
            'C25'=>['value'=>'Примечание к рейсу'],
            'C26'=>['value'=>'Unrelated'], 'E26'=>['value'=>'Do not display'],
        ]]]];
        $this->assertSame([
            ['code'=>'56 EC', 'description'=>'Подготовка грузовой докум.'],
            ['code'=>'75 WI', 'description'=>'Противообледенит. обр-ка ВС'],
        ], (new FlightReportDelayReasons)->extract($data));
    }

    public function test_empty_and_absent_tables_do_not_invent_reasons(): void
    {
        $extractor = new FlightReportDelayReasons;
        $this->assertSame([], $extractor->extract([]));
        $this->assertSame([], $extractor->extract(['sheets'=>[['cells'=>[
            'A150'=>['value'=>'Причины задержки'], 'A151'=>['value'=>'Код'], 'B151'=>['value'=>'Наименование'],
            'A160'=>['value'=>'Примечание к рейсу'], 'A161'=>['value'=>'Note'], 'B161'=>['value'=>'Not a reason'],
        ]]]]));
    }

    public function test_formula_cells_are_not_displayed_as_reason_values(): void
    {
        $data = ['sheets'=>[['cells'=>[
            'A150'=>['value'=>'Причины задержки'], 'A151'=>['value'=>'Код'], 'B151'=>['value'=>'Наименование'],
            'A152'=>['value'=>'56 EC'], 'B152'=>['value'=>'=SOME_FORMULA()', 'type'=>'f'],
        ]]]];
        $this->assertSame([], (new FlightReportDelayReasons)->extract($data));
    }
}
