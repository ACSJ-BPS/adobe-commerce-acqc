import { TableView, TableHeader, Column, TableBody, Row, Cell } from '@adobe/react-spectrum';

function OrderTable({ tenThousandRows }) {
  return (
    <TableView aria-label="Orders">
      <TableHeader><Column>Name</Column></TableHeader>
      <TableBody items={tenThousandRows}>
        {row => <Row><Cell>{row.name}</Cell></Row>}
      </TableBody>
    </TableView>
  );
}
